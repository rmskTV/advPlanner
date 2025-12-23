<?php

namespace Modules\MediaHills\app\Services;

use Illuminate\Support\Facades\Log;
use Modules\MediaHills\app\Contracts\MediaPlanParserInterface;
use Modules\MediaHills\app\Parsers\CsvGridParser;
use Modules\MediaHills\app\Parsers\HorizontalGridParser;
use Modules\MediaHills\app\Parsers\HtmlTableParser;

class MediaPlanAnalyzerService
{
    private array $parsers = [];

    public function __construct(
        private AudienceMatchingService $audienceService
    ) {
        // Регистрируем доступные парсеры
        $this->registerParser(new HtmlTableParser);
        $this->registerParser(new HorizontalGridParser);
        $this->registerParser(new CsvGridParser);
        // Здесь можно добавлять другие парсеры
    }

    /**
     * Регистрация парсера
     */
    public function registerParser(MediaPlanParserInterface $parser): void
    {
        $this->parsers[] = $parser;
    }

    /**
     * Анализ медиаплана
     */
    public function analyze(string $filePath, ?int $year = null): array
    {
        // Находим подходящий парсер
        $parser = $this->findParser($filePath);

        if (! $parser) {
            throw new \Exception('Не найден парсер для файла: '.basename($filePath));
        }

        Log::info('Используется парсер: '.get_class($parser));

        // Парсим файл
        $data = $parser->parse($filePath);

        // Извлекаем год из имени файла если не передан
        if ($year === null) {
            $year = $this->extractYearFromFilename($filePath) ?? now()->year;
        }

        Log::info('Анализ медиаплана', [
            'channel' => $data['channel'],
            'period' => "{$data['start_date']} - {$data['end_date']}",
            'placements' => count($data['placements']),
            'year' => $year,
        ]);

        // Анализируем размещения
        $analytics = $this->audienceService->analyze(
            $data['placements'],
            $data['channel'],
            $year
        );

        return [
            'channel' => $data['channel'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'year' => $year,
            'spots' => $analytics,
            'total_placements' => count($data['placements']),
        ];
    }

    /**
     * Поиск подходящего парсера
     */
    private function findParser(string $filePath): ?MediaPlanParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($filePath)) {
                return $parser;
            }
        }

        return null;
    }

    /**
     * Извлечение года из имени файла
     */
    private function extractYearFromFilename(string $filePath): ?int
    {
        $filename = basename($filePath);

        // Ищем паттерн 8 цифр подряд (DDMMYYYY)
        if (preg_match('/(\d{2})(\d{2})(\d{4})/', $filename, $matches)) {
            return (int) $matches[3];
        }

        // Ищем просто 4 цифры (2025, 2024...)
        if (preg_match('/20\d{2}/', $filename, $matches)) {
            return (int) $matches[0];
        }

        return null;
    }
}
