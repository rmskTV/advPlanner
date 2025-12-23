<?php

namespace Modules\MediaHills\app\Parsers;

use Illuminate\Support\Facades\Log;
use Modules\MediaHills\app\Contracts\MediaPlanParserInterface;
use Modules\MediaHills\app\DTOs\PlacementData;

class CsvGridParser implements MediaPlanParserInterface
{
    private array $datesMap = [];

    private ?string $channelName = null;

    public function supports(string $filePath): bool
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return $extension === 'csv';
    }

    /**
     * @throws \Exception
     */
    public function parse(string $filePath): array
    {
        // Сброс состояния
        $this->datesMap = [];
        $this->channelName = null;

        Log::info("Парсинг CSV медиаплана: $filePath");

        // Читаем CSV с учетом кодировки
        $rows = $this->readCsvFile($filePath);

        if (empty($rows)) {
            throw new \Exception('CSV файл пустой или не читается');
        }

        // Первая строка - заголовок
        $headerRow = $rows[0];

        // Извлекаем название канала (первая ячейка)
        $this->channelName = ! empty($headerRow[0]) ? trim($headerRow[0]) : 'Неизвестный канал';
        Log::info("Определен канал: {$this->channelName}");

        // Извлекаем даты из заголовка
        $this->extractDates($headerRow);

        // Остальные строки - размещения
        $placements = $this->extractPlacements($rows);

        // Определяем период
        $dates = array_values($this->datesMap);
        $startDate = reset($dates);
        $endDate = end($dates);

        return [
            'channel' => $this->channelName,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'placements' => $placements,
        ];
    }

    /**
     * Чтение CSV с автоопределением кодировки
     */
    private function readCsvFile(string $filePath): array
    {
        $content = file_get_contents($filePath);

        // Проверяем BOM и убираем
        $bom = pack('H*', 'EFBBBF');
        if (str_starts_with($content, $bom)) {
            $content = substr($content, 3);
        }

        // Определяем кодировку
        $encoding = mb_detect_encoding($content, ['UTF-8', 'Windows-1251', 'CP1251'], true);

        if (! $encoding) {
            // По умолчанию пробуем Windows-1251
            $encoding = 'Windows-1251';
        }

        Log::info("Кодировка CSV: {$encoding}");

        // Конвертируем в UTF-8
        if ($encoding !== 'UTF-8') {
            $content = iconv($encoding, 'UTF-8//IGNORE', $content);
        }

        // Заменяем разные переводы строк на \n
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        // Парсим построчно
        $rows = [];
        $lines = explode("\n", $content);

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            // Парсим строку CSV с разделителем ;
            $row = str_getcsv($line, ';');

            // Очищаем от лишних пробелов
            $row = array_map('trim', $row);

            $rows[] = $row;
        }

        Log::info('Прочитано строк из CSV: '.count($rows));

        // DEBUG: показываем первые 3 строки
        Log::debug('Первые строки CSV:', array_slice($rows, 0, 3));

        return $rows;
    }

    /**
     * Извлечение дат из заголовка
     */
    /**
     * Извлечение дат из заголовка
     */
    private function extractDates(array $headerRow): void
    {
        Log::info('Заголовок CSV:', ['header' => $headerRow]);

        // ИЗВЛЕКАЕМ НАЗВАНИЕ КАНАЛА из первой ячейки
        if (! empty($headerRow[0]) && empty($this->channelName)) {
            $this->channelName = trim($headerRow[0]);
            Log::info("Найден канал из заголовка: {$this->channelName}");
        }

        // Первые 2 колонки: "КАНАЛ/БЛОК" и "Время начала"
        // Остальные - даты

        $dates = [];
        $monthMap = $this->getMonthMap();

        for ($i = 2; $i < count($headerRow); $i++) {
            $value = trim($headerRow[$i]);

            if (empty($value)) {
                continue;
            }

            Log::debug("Проверяем колонку $i: '{$value}'");

            // Формат: "29.окт", "30.сен", "01.окт"

            // Паттерн: число.месяц_кириллица
            if (preg_match('/^(\d{1,2})\.(\S+)$/u', $value, $matches)) {
                $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                $monthWord = mb_strtolower($matches[2], 'UTF-8');

                // Ищем месяц в словаре
                if (isset($monthMap[$monthWord])) {
                    $month = str_pad($monthMap[$monthWord], 2, '0', STR_PAD_LEFT);
                    $dateStr = "{$day}.{$month}";

                    $this->datesMap[$i] = $dateStr;
                    $dates[] = $dateStr;

                    Log::debug("Дата распознана: {$value} → {$dateStr}");
                } else {
                    Log::warning("Неизвестный месяц: {$monthWord}");
                }
            }
            // Формат: "29.10" (уже числовой)
            elseif (preg_match('/^(\d{1,2})\.(\d{1,2})$/', $value, $matches)) {
                $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
                $dateStr = "{$day}.{$month}";

                $this->datesMap[$i] = $dateStr;
                $dates[] = $dateStr;
            }
        }

        if (empty($dates)) {
            throw new \Exception('Даты не найдены в заголовке CSV');
        }

        $lastDate = end($dates);
        Log::info('Найдено дат: '.count($this->datesMap).
            " (с {$dates[0]} по {$lastDate})");
    }

    /**
     * Словарь месяцев
     */
    private function getMonthMap(): array
    {
        return [
            'янв' => 1, 'января' => 1,
            'фев' => 2, 'февраля' => 2,
            'мар' => 3, 'марта' => 3,
            'апр' => 4, 'апреля' => 4,
            'май' => 5, 'мая' => 5,
            'июн' => 6, 'июня' => 6,
            'июл' => 7, 'июля' => 7,
            'авг' => 8, 'августа' => 8,
            'сен' => 9, 'сентября' => 9,
            'окт' => 10, 'октября' => 10,
            'ноя' => 11, 'ноября' => 11,
            'дек' => 12, 'декабря' => 12,
        ];
    }

    /**
     * Угадывание месяца из порядка дат
     */
    private function guessMonthFromFilename(int $columnIndex): string
    {
        // Для упрощения возвращаем 10 (октябрь)
        // TODO: можно извлекать из имени файла
        return '10';
    }

    /**
     * Извлечение размещений
     */
    private function extractPlacements(array $rows): array
    {
        $placements = [];
        $currentChannel = null;

        // Пропускаем первую строку (заголовок)
        for ($rowIndex = 1; $rowIndex < count($rows); $rowIndex++) {
            $row = $rows[$rowIndex];

            if (count($row) < 2) {
                continue;
            }

            $programName = trim($row[0]);
            $timeSlot = trim($row[1]);

            if (empty($programName)) {
                continue;
            }

            // Если нет времени или это не время - может быть строка канала
            if (empty($timeSlot) || ! str_contains($timeSlot, ':')) {
                // Проверяем: это канал или пустая программа
                if (mb_strtoupper($programName) === $programName && mb_strlen($programName) < 30) {
                    $currentChannel = $programName;

                    if (! $this->channelName) {
                        $this->channelName = $currentChannel;
                        Log::info("Найден канал: {$currentChannel}");
                    }
                }

                continue;
            }

            // Парсим время
            $time = $this->parseTime($timeSlot);

            if (! $time) {
                Log::warning("Не удалось распарсить время: {$timeSlot} для программы: {$programName}");

                continue;
            }

            // Парсим размещения по датам
            foreach ($this->datesMap as $colIndex => $date) {
                if (! isset($row[$colIndex])) {
                    continue;
                }

                $cellValue = trim($row[$colIndex]);

                if (empty($cellValue)) {
                    continue;
                }

                // Извлекаем номера роликов
                $spotNumbers = $this->extractSpotNumbers($cellValue);

                foreach ($spotNumbers as $spotNumber) {
                    $placements[] = new PlacementData(
                        spotNumber: $spotNumber,
                        date: $date,
                        time: $time,
                        programName: $programName,
                        channelName: $currentChannel ?? 'Unknown'
                    );
                }
            }
        }

        Log::info('Найдено размещений: '.count($placements));

        return $placements;
    }

    /**
     * Парсинг времени
     */
    private function parseTime(string $value): ?string
    {
        // Формат: "5:25:00", "22:30:00", "19:02:00"
        if (preg_match('/^(\d{1,2}):(\d{2})/', $value, $matches)) {
            $hour = str_pad($matches[1], 2, '0', STR_PAD_LEFT);

            return "{$hour}:{$matches[2]}";
        }

        return null;
    }

    /**
     * Извлечение номеров роликов из ячейки
     */
    private function extractSpotNumbers(string $cellValue): array
    {
        $spotNumbers = [];

        // Декодируем HTML entities если есть
        $cellValue = html_entity_decode($cellValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Варианты:
        // "№1" → [1]
        // "№6,№3" → [6, 3]
        // "№6, №3" → [6, 3]
        // "13" → [13]

        // Ищем все вхождения "№N"
        if (preg_match_all('/№\s*(\d+)/', $cellValue, $matches)) {
            foreach ($matches[1] as $number) {
                $spotNumbers[] = (int) $number;
            }
        } elseif (is_numeric($cellValue)) {
            // Просто число без префикса
            $spotNumbers[] = (int) $cellValue;
        }

        return array_unique($spotNumbers);
    }
}
