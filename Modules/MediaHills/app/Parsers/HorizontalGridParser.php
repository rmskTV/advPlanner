<?php

namespace Modules\MediaHills\app\Parsers;

use DOMDocument;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Modules\MediaHills\app\Contracts\MediaPlanParserInterface;
use Modules\MediaHills\app\DTOs\PlacementData;

class HorizontalGridParser implements MediaPlanParserInterface
{
    private array $datesMap = [];

    private ?string $channelName = null;

    public function supports(string $filePath): bool
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return in_array($extension, ['mht', 'mhtml']);
    }

    public function parse(string $filePath): array
    {
        Log::info("Парсинг горизонтального медиаплана (MHT): $filePath");

        // Извлекаем и очищаем HTML из MHT
        $htmlContent = $this->extractHtmlFromMht($filePath);

        if (! $htmlContent) {
            throw new \Exception('Не удалось извлечь HTML из MHT файла');
        }

        // Парсим HTML напрямую через DOMDocument
        $rows = $this->parseHtmlTable($htmlContent);

        if (empty($rows)) {
            throw new \Exception('Не удалось извлечь таблицу из HTML');
        }

        // Ищем заголовок календаря
        $headerRowIndex = $this->findCalendarHeader($rows);

        if ($headerRowIndex === null) {
            throw new \Exception('Не найдена заголовочная строка календаря');
        }

        // Извлекаем даты
        $this->extractDates($rows[$headerRowIndex]);

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
     * Извлечение HTML из MHT файла
     */
    private function extractHtmlFromMht(string $filePath): ?string
    {
        $content = File::get($filePath);

        // Находим boundary
        if (! preg_match('/boundary="([^"]+)"/', $content, $matches)) {
            Log::error('Не найден MIME boundary в MHT');

            return null;
        }

        $boundary = $matches[1];

        // Разделяем на части
        $parts = explode('--'.$boundary, $content);

        foreach ($parts as $part) {
            // Ищем HTML секцию
            if (preg_match('/Content-Type:\s*text\/html/i', $part)) {
                // Проверяем кодировку
                $encoding = 'utf-8';
                if (preg_match('/Content-Transfer-Encoding:\s*(\S+)/i', $part, $encMatches)) {
                    $encoding = strtolower($encMatches[1]);
                }

                // Извлекаем HTML после заголовков
                $sections = preg_split('/\r?\n\r?\n/', $part, 2);

                if (isset($sections[1])) {
                    $html = $sections[1];

                    // Декодируем quoted-printable если нужно
                    if ($encoding === 'quoted-printable') {
                        $html = quoted_printable_decode($html);
                    }

                    // Убираем концевые маркеры
                    $html = preg_replace('/--'.preg_quote($boundary, '/').'.*$/s', '', $html);

                    Log::info('HTML извлечен из MHT ('.strlen($html)." байт, кодировка: {$encoding})");

                    return $html;
                }
            }
        }

        Log::error('HTML секция не найдена в MHT');

        return null;
    }

    /**
     * Парсинг HTML таблицы напрямую через DOMDocument
     */
    private function parseHtmlTable(string $html): array
    {
        // Подготовка HTML для парсинга
        $html = $this->cleanHtml($html);

        // Создаем DOMDocument
        $dom = new DOMDocument;

        // Отключаем ошибки для невалидного HTML
        libxml_use_internal_errors(true);

        // Загружаем HTML (указываем кодировку)
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();

        // Находим таблицу
        $tables = $dom->getElementsByTagName('table');

        if ($tables->length === 0) {
            throw new \Exception('Таблица не найдена в HTML');
        }

        // Берем первую таблицу
        $table = $tables->item(0);

        // Извлекаем строки
        $rows = [];
        $rowIndex = 1;

        foreach ($table->getElementsByTagName('tr') as $tr) {
            $rowData = [];
            $colIndex = 'A';

            foreach ($tr->getElementsByTagName('td') as $td) {
                // Получаем текст ячейки
                $cellValue = trim($td->textContent);

                // Получаем colspan
                $colspan = (int) ($td->getAttribute('colspan') ?: 1);

                // Заполняем ячейки с учетом colspan
                for ($i = 0; $i < $colspan; $i++) {
                    $rowData[$colIndex] = $cellValue;
                    $colIndex++;
                }
            }

            $rows[$rowIndex] = $rowData;
            $rowIndex++;
        }

        Log::info('Извлечено строк из HTML таблицы: '.count($rows));

        return $rows;
    }

    /**
     * Очистка HTML от проблемных элементов
     */
    private function cleanHtml(string $html): string
    {
        // Убираем JavaScript
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);

        // Убираем стили (оставляем только inline)
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);

        // Убираем комментарии
        $html = preg_replace('//s', '', $html);

        // Заменяем &nbsp; на пробел
        $html = str_replace('&nbsp;', ' ', $html);

        // Убираем пустые атрибуты class
        $html = preg_replace('/\s+class=3D["\'][^"\']*["\']/i', '', $html);

        return $html;
    }

    /**
     * Поиск заголовка календаря
     */
    private function findCalendarHeader(array $rows): ?int
    {
        foreach ($rows as $index => $row) {
            // Подсчитываем количество дат в строке
            $dateCount = 0;

            foreach ($row as $col => $value) {
                $value = trim($value);

                // Проверяем формат даты
                if (preg_match('/^\d{1,2}\.\d{1,2}$/', $value)) {
                    $dateCount++;
                }
            }

            // Если нашли много дат - это заголовок
            if ($dateCount >= 3) {
                Log::info("Найден заголовок календаря в строке $index (дат: $dateCount)");

                return $index;
            }
        }

        return null;
    }

    /**
     * Извлечение дат из заголовка
     */
    private function extractDates(array $headerRow): void
    {
        $dates = [];
        $foundFirstDate = false;

        foreach ($headerRow as $columnIndex => $value) {
            $value = trim($value);

            // Проверяем что это дата (формат DD.MM)
            if (preg_match('/^\d{1,2}\.\d{1,2}$/', $value)) {
                $this->datesMap[$columnIndex] = $value;
                $dates[] = $value;
                $foundFirstDate = true;
            } elseif ($foundFirstDate && empty($value)) {
                // Дошли до конца дат
                break;
            }
        }

        if (empty($dates)) {
            throw new \Exception('Даты не найдены в заголовке');
        }

        Log::info('Найдено дат: '.count($this->datesMap).
            " (с {$dates[0]} по {$dates[array_key_last($dates)]})");
    }

    /**
     * Извлечение размещений
     */
    private function extractPlacements(array $rows, int $headerRowIndex): array
    {
        $placements = [];
        $currentChannel = null;

        foreach ($rows as $index => $row) {
            // Пропускаем строки до заголовка и сам заголовок (+ строка с днями недели)
            if ($index <= $headerRowIndex + 1) {
                continue;
            }

            // Ищем первую непустую ячейку в начале строки
            $programName = '';
            $timeSlot = '';

            foreach (range('A', 'F') as $col) {
                $value = trim($row[$col] ?? '');

                if (! empty($value) && ! str_contains($value, '&')) {
                    if (str_contains($value, ':')) {
                        $timeSlot = $value;
                    } else {
                        $programName .= ($programName ? ' ' : '').$value;
                    }
                }
            }

            if (empty($programName)) {
                continue;
            }

            // Если нет времени - это строка канала
            if (empty($timeSlot)) {
                $currentChannel = $programName;

                if (! $this->channelName) {
                    $this->channelName = $currentChannel;
                    Log::info("Найден канал: {$currentChannel}");
                }

                continue;
            }

            // Парсим время
            $time = $this->parseTime($timeSlot);

            if (! $time) {
                Log::warning("Не удалось распарсить время: {$timeSlot}");

                continue;
            }

            // Парсим размещения по датам
            foreach ($this->datesMap as $columnIndex => $date) {
                $cellValue = trim($row[$columnIndex] ?? '');

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
        // Формат: "5:25:00", "22:30:00"
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

        // Убираем HTML entities
        $cellValue = html_entity_decode($cellValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Ищем все вхождения "№N"
        if (preg_match_all('/№\s*(\d+)/', $cellValue, $matches)) {
            foreach ($matches[1] as $number) {
                $spotNumbers[] = (int) $number;
            }
        } elseif (is_numeric($cellValue)) {
            $spotNumbers[] = (int) $cellValue;
        }

        return array_unique($spotNumbers);
    }
}
