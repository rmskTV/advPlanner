<?php

// Modules/Bitrix24/app/Console/Commands/ProcessChanges.php

namespace Modules\Bitrix24\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\Accounting\app\Models\ObjectChangeLog;
use Modules\Bitrix24\app\Services\SyncChangeProcessor;

class ProcessChanges extends Command
{
    protected $signature = 'bitrix24:process-changes
                            {--limit= : Maximum number of records to process}
                            {--unlock : Unlock stale records before processing}
                            {--stats : Show queue statistics and exit}';

    protected $description = 'Process pending changes from 1C to Bitrix24';

    public function handle(SyncChangeProcessor $processor): int
    {
        // Показать статистику
        if ($this->option('stats')) {
            $this->displayStats($processor);

            return self::SUCCESS;
        }

        $this->info('🚀 Starting Bitrix24 sync...');

        // Разблокировка зависших записей
        if ($this->option('unlock')) {
            $unlocked = $processor->unlockStaleRecords();
            $this->info("🔓 Unlocked {$unlocked} stale records");
        }

        // Получаем лимит
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        // Показываем сколько записей в очереди
        $totalReady = ObjectChangeLog::readyForProcessing()->count();
        $toProcess = $limit ?? $totalReady;

        $this->info("📊 Total ready: {$totalReady}, will process: {$toProcess}");

        if ($totalReady === 0) {
            $this->info('✅ Nothing to process');

            return self::SUCCESS;
        }

        // Создаём прогресс-бар
        $progressBar = $this->output->createProgressBar($toProcess);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

        try {
            // Запускаем обработку с коллбэком для прогресс-бара
            $stats = $processor->process($limit, function ($change) use ($progressBar) {
                $progressBar->advance();
            });

            $progressBar->finish();
            $this->newLine(2);

            // Выводим статистику
            $this->info('✅ Sync completed successfully');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Processed', $stats['processed']],
                    ['Errors', $stats['errors']],
                    ['Skipped', $stats['skipped']],
                    ['Total', $stats['total']],
                ]
            );

            return self::SUCCESS;

        } catch (\Exception $e) {
            $progressBar->finish();
            $this->newLine(2);
            $this->error('❌ Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Отображение статистики очереди
     */
    protected function displayStats(SyncChangeProcessor $processor): void
    {
        $stats = $processor->getQueueStats();

        $this->info('📊 Queue Statistics:');
        $this->table(
            ['Status', 'Count'],
            [
                ['Pending', $stats['pending']],
                ['Retry', $stats['retry']],
                ['Processing', $stats['processing']],
                ['Error', $stats['error']],
                ['Locked', $stats['locked']],
                ['─────────', '─────'],
                ['Ready to process', $stats['total_ready']],
            ]
        );
    }
}
