<?php

namespace Modules\MediaHills\app\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\MediaHills\app\Models\TvAudienceData;
use Modules\MediaHills\app\Models\TvChannel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class AudienceDataImportService
{
    private array $stats = [
        'processed' => 0,
        'created' => 0,
        'updated' => 0,
        'errors' => 0,
        'channels' => [],
        'error_details' => [],
    ];

    private bool $verbose = false;

    // Триггерные слова для поиска заголовочной строки
    private const HEADER_PATTERNS = [
        'col1' => ['№', 'no', 'num', 'number', 'n'],
        'col2' => ['дата', 'date', 'день', 'day'],
        'col3' => ['время', 'time', 'час', 'hour'],
    ];

    /**
     * Импорт данных из Excel файла
     */
    public function import(string $filePath, bool $verbose = false): array
    {
        $this->verbose = $verbose;
        $this->stats = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
            'channels' => [],
            'error_details' => [],
        ];

        try {
            DB::beginTransaction();

            Log::info("Начало импорта файла: $filePath");

            // Загружаем Excel файл
            $spreadsheet = IOFactory::load($filePath);

            // Получаем информацию о файле
            $sheetCount = $spreadsheet->getSheetCount();
            Log::info("Количество листов в файле: $sheetCount");

            // Берём первый лист (или можно настроить выбор)
            $worksheet = $spreadsheet->getActiveSheet();
            $sheetName = $worksheet->getTitle();
            Log::info("Используется лист: $sheetName");

            // Получаем все строки
            $rows = $worksheet->toArray(null, true, true, true);
            $totalRows = count($rows);
            Log::info("Всего строк в файле: $totalRows");

            if ($totalRows < 2) {
                throw new \Exception('Файл пуст или содержит недостаточно данных');
            }

            // ИЩЕМ ЗАГОЛОВОЧНУЮ СТРОКУ
            $headerRowIndex = $this->findHeaderRow($rows);

            if ($headerRowIndex === null) {
                throw new \Exception(
                    'Не удалось найти заголовочную строку. Ожидается, что первые 3 колонки содержат: '.
                    '№/Дата/Время (или их вариации)'
                );
            }

            Log::info('Найдена заголовочная строка на позиции: '.($headerRowIndex + 1));

            // Извлекаем заголовки
            $headers = $rows[$headerRowIndex];
            Log::info('Заголовки: '.json_encode($headers, JSON_UNESCAPED_UNICODE));

            // Извлекаем информацию о каналах
            $channelColumns = $this->extractChannelColumns($headers);

            if (empty($channelColumns)) {
                throw new \Exception("Не найдены колонки с каналами после колонки 'Время'");
            }

            Log::info('Найдено каналов: '.count($channelColumns));
            Log::info('Каналы: '.implode(', ', array_column($channelColumns, 'name')));

            // Обрабатываем строки ПОСЛЕ заголовков
            $dataRows = array_slice($rows, $headerRowIndex + 1);
            $processedCount = 0;

            Log::info('Начинаем обработку '.count($dataRows).' строк данных');

            foreach ($dataRows as $rowIndex => $row) {
                $actualRowNumber = $headerRowIndex + $rowIndex + 2; // +2 потому что индексы с 0, а строки с 1

                try {
                    $this->processRow($row, $channelColumns, $actualRowNumber);
                    $processedCount++;

                    // Логируем прогресс каждые 1000 строк
                    if ($processedCount % 1000 == 0) {
                        Log::info("Обработано строк: $processedCount / ".count($dataRows));
                    }
                } catch (\Exception $e) {
                    $this->stats['errors']++;

                    $this->stats['error_details'][] = [
                        'row' => $actualRowNumber,
                        'message' => $e->getMessage(),
                        'data' => array_slice($row, 0, 10),
                    ];

                    Log::error("Ошибка обработки строки $actualRowNumber: ".$e->getMessage(), [
                        'row_data' => array_slice($row, 0, 10),
                    ]);
                }
            }

            DB::commit();

            Log::info('Импорт завершён', $this->stats);

            return $this->getStats();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Критическая ошибка импорта', [
                'file' => $filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Поиск заголовочной строки по паттерну первых 3 колонок
     */
    private function findHeaderRow(array $rows): ?int
    {
        foreach ($rows as $index => $row) {
            // Пропускаем пустые строки
            if ($this->isEmptyRow($row)) {
                continue;
            }

            // Проверяем первые 3 колонки
            $col1 = $this->normalizeString($row['A'] ?? '');
            $col2 = $this->normalizeString($row['B'] ?? '');
            $col3 = $this->normalizeString($row['C'] ?? '');

            // Проверяем соответствие паттернам
            $match1 = $this->matchesPattern($col1, self::HEADER_PATTERNS['col1']);
            $match2 = $this->matchesPattern($col2, self::HEADER_PATTERNS['col2']);
            $match3 = $this->matchesPattern($col3, self::HEADER_PATTERNS['col3']);

            if ($match2 && $match3) {
                Log::info('Найдена заголовочная строка', [
                    'index' => $index,
                    'col1' => $row['A'],
                    'col2' => $row['B'],
                    'col3' => $row['C'],
                ]);

                return $index;
            }
        }

        return null;
    }

    /**
     * Проверка, пустая ли строка
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (! empty(trim($cell))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Нормализация строки для сравнения
     */
    private function normalizeString(string $str): string
    {
        $str = mb_strtolower(trim($str), 'UTF-8');
        // Убираем специальные символы и пробелы
        $str = preg_replace('/[^a-zа-яё0-9]/ui', '', $str);

        return $str;
    }

    /**
     * Проверка соответствия строки одному из паттернов
     */
    private function matchesPattern(string $value, array $patterns): bool
    {
        if (empty($value)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if ($value === $this->normalizeString($pattern)) {
                return true;
            }
            // Также проверяем вхождение
            if (str_contains($value, $this->normalizeString($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Извлекаем названия каналов и их позиции в массиве
     */
    private function extractChannelColumns(array $headers): array
    {
        $channels = [];

        Log::info('Извлечение каналов из заголовков...');

        // Начинаем с колонки D (4-я колонка, индекс 3)
        // Первые 3 колонки: №, Дата, Время
        $startFromColumn = 'D';
        $started = false;

        foreach ($headers as $columnIndex => $value) {
            // Начинаем с колонки D
            if (! $started) {
                if ($columnIndex === $startFromColumn) {
                    $started = true;
                } else {
                    continue;
                }
            }

            $channelName = trim($value);

            // Пропускаем пустые колонки
            if (empty($channelName)) {
                continue;
            }

            $channels[$columnIndex] = [
                'name' => $channelName,
                'model' => TvChannel::findOrCreateByName($channelName),
            ];

            if (! in_array($channelName, $this->stats['channels'])) {
                $this->stats['channels'][] = $channelName;
            }

            Log::info("Найден канал в колонке $columnIndex: $channelName");
        }

        return $channels;
    }

    /**
     * Обработка одной строки данных
     */
    private function processRow(array $row, array $channelColumns, int $rowNumber): void
    {
        // Колонки A, B, C - это №, Дата, Время
        $dateColumn = $row['B'] ?? null;
        $timeColumn = $row['C'] ?? null;

        // Пропускаем пустые строки
        if (empty($dateColumn) && empty($timeColumn)) {
            if ($this->verbose) {
                Log::debug("Пропущена пустая строка $rowNumber");
            }

            return;
        }

        // Объединяем дату и время в один datetime
        $datetime = $this->parseDateTime($dateColumn, $timeColumn);

        if (! $datetime) {
            throw new \Exception(
                "Не удалось распарсить дату/время. Дата: '".
                ($dateColumn ?? 'NULL')."', Время: '".
                ($timeColumn ?? 'NULL')."'"
            );
        }

        $hasData = false;

        // Обрабатываем данные по каждому каналу
        foreach ($channelColumns as $columnIndex => $channelData) {
            if (! isset($row[$columnIndex])) {
                continue;
            }

            $audienceValue = $this->parseAudienceValue($row[$columnIndex]);

            if ($audienceValue === null) {
                continue;
            }

            $this->upsertAudienceData(
                $channelData['model']->id,
                $datetime,
                $audienceValue
            );

            $hasData = true;
        }

        if (! $hasData && $this->verbose) {
            Log::debug("В строке $rowNumber нет данных по аудитории");
        }
    }

    /**
     * Парсинг даты и времени в единый datetime
     */
    private function parseDateTime($dateValue, $timeValue): ?Carbon
    {
        try {
            $date = $this->parseDate($dateValue);
            $time = $this->parseTime($timeValue);

            if (! $date || ! $time) {
                Log::warning('Не удалось распарсить дату или время', [
                    'date_value' => $dateValue,
                    'time_value' => $timeValue,
                    'parsed_date' => $date,
                    'parsed_time' => $time,
                ]);

                return null;
            }

            return Carbon::parse($date.' '.$time);
        } catch (\Exception $e) {
            Log::warning('Ошибка парсинга datetime', [
                'date' => $dateValue,
                'time' => $timeValue,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Парсинг даты из Excel
     */
    private function parseDate($value): ?string
    {
        try {
            // Excel serial date (число больше 1)
            if (is_numeric($value) && $value > 1) {
                $date = ExcelDate::excelToDateTimeObject($value);

                return $date->format('Y-m-d');
            }

            // Строковая дата в формате d.m.Y
            $date = \DateTime::createFromFormat('d.m.Y', trim($value));
            if ($date) {
                return $date->format('Y-m-d');
            }

            // Строковая дата в формате Y-m-d
            $date = \DateTime::createFromFormat('Y-m-d', trim($value));
            if ($date) {
                return $date->format('Y-m-d');
            }

            // Пробуем стандартный парсинг
            $date = \DateTime::createFromFormat('d/m/Y', trim($value));
            if ($date) {
                return $date->format('Y-m-d');
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Парсинг времени
     */
    private function parseTime($value): ?string
    {
        try {
            // Excel serial time (дробное число меньше 1)
            if (is_numeric($value) && $value < 1 && $value > 0) {
                $time = ExcelDate::excelToDateTimeObject($value);

                return $time->format('H:i:s');
            }

            // Строковое время
            $value = trim($value);

            if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $matches)) {
                $hour = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                $minute = $matches[2];
                $second = $matches[3] ?? '00';

                return "{$hour}:{$minute}:{$second}";
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Парсинг значения аудитории
     */
    private function parseAudienceValue($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Заменяем запятую на точку
        $value = str_replace(',', '.', trim($value));

        // Убираем пробелы (для чисел типа "1 234.56")
        $value = str_replace(' ', '', $value);

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Обновление или создание записи (upsert)
     */
    private function upsertAudienceData(int $channelId, Carbon $datetime, float $audienceValue): void
    {
        $exists = TvAudienceData::where('channel_id', $channelId)
            ->where('datetime', $datetime)
            ->exists();

        TvAudienceData::updateOrCreate(
            [
                'channel_id' => $channelId,
                'datetime' => $datetime,
            ],
            [
                'audience_value' => $audienceValue,
            ]
        );

        $this->stats['processed']++;
        if ($exists) {
            $this->stats['updated']++;
        } else {
            $this->stats['created']++;
        }
    }

    /**
     * Получить статистику импорта
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}
