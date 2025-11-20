<?php

namespace Modules\MediaHills\app\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Modules\MediaHills\app\Services\AudienceDataImportService;

class ImportAudienceDataCommand extends Command
{
    protected $signature = 'mediahills:import
                            {--path= : Путь к папке с файлами (по умолчанию storage/app/mediahills/import)}
                            {--archive : Переместить обработанные файлы в папку archive}
                            {--delete : Удалить файлы после обработки}
                            {--debug : Подробный вывод ошибок}';

    protected $description = 'Импорт данных телесмотрения из Excel файлов';

    public function __construct(
        private AudienceDataImportService $importService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🚀 Начинаем импорт данных телесмотрения...');
        $this->newLine();

        // Определяем путь к папке
        $path = $this->option('path')
            ?? storage_path('app/mediahills/import');

        // Создаём папки если их нет
        $this->ensureDirectoriesExist($path);

        // Получаем список файлов
        $files = $this->getExcelFiles($path);

        if (empty($files)) {
            $this->warn('📂 Файлы для импорта не найдены в папке: '.$path);

            return Command::SUCCESS;
        }

        $this->info('📊 Найдено файлов: '.count($files));
        $this->newLine();

        // Обрабатываем каждый файл
        $totalStats = [
            'files_processed' => 0,
            'files_success' => 0,
            'files_failed' => 0,
            'total_records' => 0,
            'total_created' => 0,
            'total_updated' => 0,
            'total_errors' => 0,
        ];

        $progressBar = $this->output->createProgressBar(count($files));
        $progressBar->start();

        $debugMode = $this->option('debug') || $this->output->isVerbose();

        foreach ($files as $file) {
            $this->newLine(2);
            $this->info('📄 Обработка файла: '.basename($file));

            try {
                // Включаем режим подробного вывода для сервиса
                $stats = $this->importService->import($file, $debugMode);

                $totalStats['files_success']++;
                $totalStats['total_records'] += $stats['processed'];
                $totalStats['total_created'] += $stats['created'];
                $totalStats['total_updated'] += $stats['updated'];
                $totalStats['total_errors'] += $stats['errors'];

                $this->displayFileStats($stats);

                // Выводим ошибки если есть
                if (! empty($stats['error_details']) && $debugMode) {
                    $this->newLine();
                    $this->warn('⚠️  Детали ошибок:');
                    foreach (array_slice($stats['error_details'], 0, 10) as $error) {
                        $this->line("   Строка {$error['row']}: {$error['message']}");
                        if (! empty($error['data'])) {
                            $this->line('      Данные: '.json_encode($error['data'], JSON_UNESCAPED_UNICODE));
                        }
                    }

                    if (count($stats['error_details']) > 10) {
                        $this->warn('   ... и ещё '.(count($stats['error_details']) - 10).' ошибок');
                    }
                }

                // Обрабатываем файл после успешного импорта
                $this->handleProcessedFile($file, $path, true);

            } catch (\Exception $e) {
                $totalStats['files_failed']++;
                $this->error('❌ Ошибка: '.$e->getMessage());
                $this->newLine();

                if ($debugMode) {
                    $this->error('Stack trace:');
                    $this->line($e->getTraceAsString());
                }

                // Перемещаем проблемный файл в папку errors
                $this->handleProcessedFile($file, $path, false);
            }

            $totalStats['files_processed']++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Итоговая статистика
        $this->displayTotalStats($totalStats);

        return Command::SUCCESS;
    }

    /**
     * Создание необходимых директорий
     */
    private function ensureDirectoriesExist(string $basePath): void
    {
        $directories = [
            $basePath,
            $basePath.'/archive',
            $basePath.'/errors',
        ];

        foreach ($directories as $dir) {
            if (! File::exists($dir)) {
                File::makeDirectory($dir, 0755, true);
                $this->info("✅ Создана папка: $dir");
            }
        }
    }

    /**
     * Получение списка Excel файлов из папки
     */
    private function getExcelFiles(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $files = File::files($path);

        return array_filter($files, function ($file) {
            return in_array(
                strtolower($file->getExtension()),
                ['xlsx', 'xls']
            );
        });
    }

    /**
     * Обработка файла после импорта
     */
    private function handleProcessedFile(string $file, string $basePath, bool $success): void
    {
        if ($this->option('delete')) {
            File::delete($file);
            $this->info('🗑️  Файл удалён');

            return;
        }

        if ($this->option('archive') || ! $success) {
            $targetDir = $success ? 'archive' : 'errors';
            $targetPath = $basePath.'/'.$targetDir.'/'.basename($file);

            if (File::exists($targetPath)) {
                $filename = pathinfo($file, PATHINFO_FILENAME);
                $extension = pathinfo($file, PATHINFO_EXTENSION);
                $timestamp = now()->format('Y-m-d_His');
                $targetPath = $basePath.'/'.$targetDir.'/'.$filename.'_'.$timestamp.'.'.$extension;
            }

            File::move($file, $targetPath);
            $this->info("📦 Файл перемещён в: $targetDir/");
        }
    }

    /**
     * Отображение статистики по файлу
     */
    private function displayFileStats(array $stats): void
    {
        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Обработано записей', $stats['processed']],
                ['Создано новых', $stats['created']],
                ['Обновлено', $stats['updated']],
                ['Ошибок', $stats['errors']],
                ['Каналов', count($stats['channels'])],
            ]
        );

        if (! empty($stats['channels'])) {
            $this->info('📺 Каналы: '.implode(', ', $stats['channels']));
        }
    }

    /**
     * Отображение итоговой статистики
     */
    private function displayTotalStats(array $stats): void
    {
        $this->info('═══════════════════════════════════════');
        $this->info('           ИТОГОВАЯ СТАТИСТИКА         ');
        $this->info('═══════════════════════════════════════');

        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Всего файлов обработано', $stats['files_processed']],
                ['Успешно', $stats['files_success']],
                ['С ошибками', $stats['files_failed']],
                ['Всего записей обработано', $stats['total_records']],
                ['Создано новых записей', $stats['total_created']],
                ['Обновлено записей', $stats['total_updated']],
                ['Ошибок при обработке', $stats['total_errors']],
            ]
        );

        if ($stats['files_success'] > 0) {
            $this->info('✅ Импорт завершён успешно!');
        } else {
            $this->error('❌ Все файлы обработаны с ошибками');
        }
    }
}
