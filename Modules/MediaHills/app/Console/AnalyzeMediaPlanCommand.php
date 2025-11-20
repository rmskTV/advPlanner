<?php

namespace Modules\MediaHills\app\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Modules\MediaHills\app\Services\MediaPlanAnalyzerService;

class AnalyzeMediaPlanCommand extends Command
{
    protected $signature = 'mediahills:analyze
                            {--path= : Путь к папке с медиапланами (по умолчанию storage/app/mediahills/plans)}
                            {--year= : Год для анализа (если не указан - определяется автоматически)}
                            {--format=table : Формат вывода (table, json, csv)}';

    protected $description = 'Анализ медиапланов и расчет охвата роликов';

    public function __construct(
        private MediaPlanAnalyzerService $analyzerService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('📊 Анализ медиапланов...');
        $this->newLine();

        $path = $this->option('path') ?? storage_path('app/mediahills/plans');
        $year = $this->option('year') ? (int) $this->option('year') : null;

        // Создаём папку если её нет
        if (! File::exists($path)) {
            File::makeDirectory($path, 0755, true);
            $this->warn("📂 Создана папка: $path");
            $this->warn('Поместите файлы медиапланов в эту папку и запустите команду снова.');

            return Command::SUCCESS;
        }

        // Получаем файлы
        $files = $this->getMediaPlanFiles($path);

        if (empty($files)) {
            $this->warn("📂 Файлы медиапланов не найдены в: $path");
            $this->info('Поддерживаются форматы: .html, .htm, .xlsx, .xls, .mht');

            return Command::SUCCESS;
        }

        $this->info('📄 Найдено файлов: '.count($files));
        $this->newLine();

        // Анализируем каждый файл
        foreach ($files as $file) {
            $this->analyzeFile($file, $year);
        }

        return Command::SUCCESS;
    }

    /**
     * Анализ одного файла
     */
    private function analyzeFile(string $filePath, ?int $year): void
    {
        $this->info('═══════════════════════════════════════════════════');
        $this->info('📄 Файл: '.basename($filePath));
        $this->info('═══════════════════════════════════════════════════');
        $this->newLine();

        try {
            $result = $this->analyzerService->analyze($filePath, $year);

            $this->displayResults($result);

        } catch (\Exception $e) {
            $this->error('❌ Ошибка: '.$e->getMessage());

            if ($this->option('debug')) {
                $this->line($e->getTraceAsString());
            }
        }

        $this->newLine(2);
    }

    /**
     * Отображение результатов
     */
    private function displayResults(array $result): void
    {
        // Заголовок
        $this->info("📺 Канал: {$result['channel']}");
        $this->info("📅 Период: {$result['start_date']} - {$result['end_date']} ({$result['year']})");
        $this->info("📊 Всего размещений: {$result['total_placements']}");
        $this->newLine();

        $format = $this->option('format');

        switch ($format) {
            case 'json':
                $this->displayJson($result);
                break;
            case 'csv':
                $this->displayCsv($result);
                break;
            default:
                $this->displayTable($result);
        }
    }

    /**
     * Отображение в виде таблицы
     */
    private function displayTable(array $result): void
    {
        $spots = $result['spots'];

        if (empty($spots)) {
            $this->warn('Размещений не найдено');

            return;
        }

        // Детальная информация по каждому ролику
        $tableData = [];
        $totalViews = 0;
        $totalPlacements = 0;
        $totalMissing = 0;

        foreach ($spots as $spotNumber => $analytics) {
            $tableData[] = [
                'Ролик №'.$spotNumber,
                $analytics->totalPlacements,
                $analytics->foundPlacements,
                $analytics->missingPlacements,
                number_format($analytics->totalAudience, 3, '.', ' '),
                number_format($analytics->getAverageAudience(), 3, '.', ' '),
            ];

            $totalViews += $analytics->totalAudience;
            $totalPlacements += $analytics->totalPlacements;
            $totalMissing += $analytics->missingPlacements;
        }

        $this->table(
            ['Ролик', 'Выходов', 'Найдено', 'Нет данных', 'Охват (тыс.)', 'Средний'],
            $tableData
        );

        // Итоговая статистика
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════');
        $this->info('                   ИТОГО');
        $this->info('═══════════════════════════════════════════════════');

        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Уникальных роликов', count($spots)],
                ['Всего размещений', $totalPlacements],
                ['Общий расчетный охват', number_format($totalViews, 3, '.', ' ').' тыс. просмотров'],
                ['Средний охват на размещение', number_format($totalViews / $totalPlacements, 3, '.', ' ').' тыс.'],
                ['Слотов без данных', $totalMissing.' ('.round($totalMissing / $totalPlacements * 100, 1).'%)'],
            ]
        );

        // Предупреждения
        if ($totalMissing > 0) {
            $this->newLine();
            $this->warn('⚠️  ПРЕДУПРЕЖДЕНИЯ:');
            $this->warn("   Для {$totalMissing} слотов нет данных об аудитории");

            foreach ($spots as $spotNumber => $analytics) {
                if ($analytics->missingPlacements > 0) {
                    $percent = round($analytics->missingPlacements / $analytics->totalPlacements * 100, 1);
                    $this->warn("   Ролик №{$spotNumber}: нет данных для {$analytics->missingPlacements} выходов ({$percent}%)");
                }
            }
        }
    }

    /**
     * Отображение в JSON
     */
    private function displayJson(array $result): void
    {
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * Отображение в CSV
     */
    private function displayCsv(array $result): void
    {
        $this->line('Ролик;Выходов;Найдено;Нет данных;Охват (тыс.);Средний');

        foreach ($result['spots'] as $spotNumber => $analytics) {
            $this->line(sprintf(
                '%d;%d;%d;%d;%.3f;%.3f',
                $spotNumber,
                $analytics->totalPlacements,
                $analytics->foundPlacements,
                $analytics->missingPlacements,
                $analytics->totalAudience,
                $analytics->getAverageAudience()
            ));
        }
    }

    /**
     * Получение файлов медиапланов
     */
    private function getMediaPlanFiles(string $path): array
    {
        if (!File::exists($path)) {
            return [];
        }

        $files = File::files($path);

        return array_filter($files, function ($file) {
            $ext = strtolower($file->getExtension());
            return in_array($ext, ['html', 'htm', 'xlsx', 'xls', 'mht', 'mhtml', 'csv']);
        });
    }
}
