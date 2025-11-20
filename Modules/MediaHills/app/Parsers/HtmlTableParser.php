<?php

namespace Modules\MediaHills\app\Parsers;

use Illuminate\Support\Facades\Log;
use Modules\MediaHills\app\Contracts\MediaPlanParserInterface;
use Modules\MediaHills\app\DTOs\PlacementData;
use PhpOffice\PhpSpreadsheet\IOFactory;

class HtmlTableParser implements MediaPlanParserInterface
{
    private array $datesMap = [];

    private ?string $channelName = null;

    public function supports(string $filePath): bool
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (!in_array($ext, ['htm', 'html'])) {
            return false;
        }

        // Дополнительная проверка: читаем первые строки
        try {
            $reader = IOFactory::createReader('Html');
            $spreadsheet = $reader->load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray(null, true, true, true);

            // Ищем признак формата 1
            foreach (array_slice($rows, 0, 20) as $row) {
                $col1 = mb_strtolower(trim($row['A'] ?? ''), 'UTF-8');
                if (str_contains($col1, 'дата')) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }


    public function parse(string $filePath): array
    {
        Log::info("Парсинг HTML медиаплана: $filePath");

        // PhpSpreadsheet умеет читать HTML таблицы
        $reader = IOFactory::createReader('Html');
        $spreadsheet = $reader->load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray(null, true, true, true);

        // Ищем заголовок с датами
        $headerRowIndex = $this->findHeaderRow($rows);

        if ($headerRowIndex === null) {
            throw new \Exception('Не найдена заголовочная строка с датами');
        }

        // Извлекаем даты и название канала
        $this->extractDatesAndChannel($rows[$headerRowIndex]);

        // Парсим размещения
        $placements = $this->extractPlacements($rows, $headerRowIndex);

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
     * Поиск заголовочной строки
     */
    private function findHeaderRow(array $rows): ?int
    {
        foreach ($rows as $index => $row) {
            $col1 = mb_strtolower(trim($row['A'] ?? ''), 'UTF-8');

            // Ищем строку с "ДАТА" в первой колонке
            if (str_contains($col1, 'дата') || $col1 === 'date') {
                // Проверяем что во второй колонке название канала (не пусто и не "время")
                $col2 = mb_strtolower(trim($row['B'] ?? ''), 'UTF-8');
                if (! empty($col2) && ! str_contains($col2, 'врем')) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * Извлечение дат и названия канала из заголовка
     */
    private function extractDatesAndChannel(array $headerRow): void
    {
        // Название канала во второй колонке
        $this->channelName = trim($headerRow['B'] ?? '');

        Log::info("Найден канал: {$this->channelName}");

        // Даты начинаются с колонки C
        $columnIndex = 'C';
        $dates = [];

        while (isset($headerRow[$columnIndex])) {
            $value = trim($headerRow[$columnIndex]);

            // Проверяем что это дата (формат DD.MM)
            if (preg_match('/^\d{1,2}\.\d{1,2}$/', $value)) {
                $this->datesMap[$columnIndex] = $value;
                $dates[] = $value;
            }

            // Переходим к следующей колонке
            $columnIndex++;
            if ($columnIndex > 'ZZ') {
                break;
            } // Защита от бесконечного цикла
        }

        Log::info('Найдено дат: '.count($this->datesMap)." (с {$dates[0]} по {$dates[array_key_last($dates)]})");
    }

    /**
     * Извлечение размещений из строк данных
     */
    private function extractPlacements(array $rows, int $headerRowIndex): array
    {
        $placements = [];

        foreach ($rows as $index => $row) {
            // Пропускаем строки до заголовка и сам заголовок
            if ($index <= $headerRowIndex + 2) { // +2 для строк с днями недели и кол-вом размещений
                continue;
            }

            // Проверяем что это строка с размещением
            $timeSlot = $this->parseTimeSlot($row['A'] ?? '');

            if (! $timeSlot) {
                continue; // Это разделитель или пустая строка
            }

            $programName = trim($row['B'] ?? '');

            if (empty($programName)) {
                continue;
            }

            // Парсим размещения по датам
            foreach ($this->datesMap as $columnIndex => $date) {
                $cellValue = trim($row[$columnIndex] ?? '');

                // Проверяем что в ячейке номер ролика (число)
                if (is_numeric($cellValue) && $cellValue > 0) {
                    $spotNumber = (int) $cellValue;

                    $placements[] = new PlacementData(
                        spotNumber: $spotNumber,
                        date: $date,
                        time: $timeSlot,
                        programName: $programName,
                        channelName: $this->channelName
                    );
                }
            }
        }

        Log::info('Найдено размещений: '.count($placements));

        return $placements;
    }

    /**
     * Парсинг временного интервала
     */
    private function parseTimeSlot(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        // Формат: "06:05 - 06:05" или "06:05 - 06:07"
        if (preg_match('/^(\d{1,2}:\d{2})\s*-\s*\d{1,2}:\d{2}$/', $value, $matches)) {
            return $matches[1]; // Берем начальное время
        }

        return null;
    }
}
