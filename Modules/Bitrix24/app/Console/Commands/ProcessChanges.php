<?php

namespace Modules\Bitrix24\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\Accounting\app\Models\ObjectChangeLog;
use Modules\Bitrix24\app\Services\Pull\Bitrix24PullService;
use Modules\Bitrix24\app\Services\SyncChangeProcessor;

class ProcessChanges extends Command
{
    protected $signature = 'bitrix24:process-changes
                            {--direction= : Sync direction: "pull" (B24→Laravel), "push" (Laravel→B24), or both by default}
                            {--entity= : Specific entity to pull (only for pull mode: Requisite, Contact, Contract, Product, Invoice)}
                            {--limit= : Maximum number of records to process (only for push mode)}
                            {--unlock : Unlock stale records before processing (only for push mode)}
                            {--dry-run : Preview changes without saving (only for pull mode)}
                            {--stats : Show statistics and exit}';

    protected $description = 'Process B24 sync in both directions (or specific direction if specified)';

    public function handle(
        SyncChangeProcessor $pushProcessor,
        Bitrix24PullService $pullService
    ): int {
        // Режим статистики
        if ($this->option('stats')) {
            $this->displayAllStats($pushProcessor, $pullService);
            return self::SUCCESS;
        }

        $direction = $this->option('direction');
        $isDryRun = $this->option('dry-run');

        // Dry-run только для pull
        if ($isDryRun && (!$direction || $direction === 'push')) {
            $this->warn('⚠️  --dry-run only works with pull mode');
            $this->line('Use: --dry-run --direction=pull');
            return self::FAILURE;
        }

        // Определяем режим работы
        $doPull = !$direction || $direction === 'pull';
        $doPush = !$direction || $direction === 'push';

        // В dry-run режиме только pull
        if ($isDryRun) {
            $doPush = false;
        }

        $success = true;

        // === PULL (B24 → Laravel) ===
        if ($doPull) {
            if ($isDryRun) {
                $this->info('🔍 DRY RUN MODE (B24 → Laravel) - No changes will be saved');
            } else {
                $this->info('🔽 Starting PULL (B24 → Laravel)...');
            }
            $this->newLine();

            try {
                $pullResult = $this->handlePull($pullService, $isDryRun);

                if ($pullResult !== self::SUCCESS) {
                    $success = false;
                }
            } catch (\Exception $e) {
                $this->error('❌ Pull failed: ' . $e->getMessage());
                $success = false;
            }

            $this->newLine();
        }

        // === PUSH (Laravel → B24) ===
        if ($doPush) {
            $this->info('🚀 Starting PUSH (Laravel → B24)...');
            $this->newLine();

            try {
                $pushResult = $this->handlePush($pushProcessor);

                if ($pushResult !== self::SUCCESS) {
                    $success = false;
                }
            } catch (\Exception $e) {
                $this->error('❌ Push failed: ' . $e->getMessage());
                $success = false;
            }

            $this->newLine();
        }

        // Итоговое сообщение
        if ($success) {
            if ($isDryRun) {
                $this->info('✅ Dry run completed successfully (no changes saved)');
            } else {
                $this->info('✅ Synchronization completed successfully');
            }
            return self::SUCCESS;
        } else {
            $this->error('⚠️  Synchronization completed with errors');
            return self::FAILURE;
        }
    }

    /**
     * Импорт из B24
     */
    protected function handlePull(Bitrix24PullService $pullService, bool $isDryRun = false): int
    {
        try {
            $entity = $this->option('entity');
            $verbose = $this->output->isVerbose(); // ← Используем встроенный метод

            if ($entity) {
                // Импорт одной сущности
                $this->line("  Pulling {$entity}...");
                $stats = $pullService->pullEntity($entity, $isDryRun, $verbose ? $this : null);
                $this->displayPullStats($entity, $stats, $isDryRun);
            } else {
                // Импорт всех сущностей
                $allStats = $pullService->pullAll($isDryRun, $verbose ? $this : null);

                foreach ($allStats as $entityType => $stats) {
                    $this->displayPullStats($entityType, $stats, $isDryRun);
                }
            }

            if ($isDryRun) {
                $this->info('  ✓ Dry run completed (no changes saved)');
            } else {
                $this->info('  ✓ Pull completed');
            }
            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('  ✗ Pull failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Отправка в B24
     */
    protected function handlePush(SyncChangeProcessor $processor): int
    {
        // Разблокировка зависших записей
        if ($this->option('unlock')) {
            $unlocked = $processor->unlockStaleRecords();
            $this->line("  🔓 Unlocked {$unlocked} stale records");
        }

        // Получаем лимит
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        // Показываем сколько записей в очереди
        $totalReady = ObjectChangeLog::readyForProcessing(
            $processor->getSupportedTypes(),
            '1C'
        )->count();

        $toProcess = $limit ?? $totalReady;

        $this->line("  📊 Queue: {$totalReady} ready, will process: {$toProcess}");

        if ($totalReady === 0) {
            $this->line('  ℹ Nothing to process');
            return self::SUCCESS;
        }

        // Создаём прогресс-бар
        $progressBar = $this->output->createProgressBar($toProcess);
        $progressBar->setFormat('  %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

        try {
            // Запускаем обработку
            $stats = $processor->process($limit, function ($change) use ($progressBar) {
                $progressBar->advance();
            });

            $progressBar->finish();
            $this->newLine();

            // Выводим статистику
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Processed', $stats['processed']],
                    ['Errors', $stats['errors']],
                    ['Skipped', $stats['skipped']],
                    ['Total', $stats['total']],
                ]
            );

            $this->info('  ✓ Push completed');
            return self::SUCCESS;

        } catch (\Exception $e) {
            $progressBar->finish();
            $this->newLine();
            $this->error('  ✗ Push failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Отображение статистики pull
     */
    protected function displayPullStats(string $entity, array $stats, bool $isDryRun = false): void
    {
        $hasError = !empty($stats['error_message']);

        $prefix = $isDryRun ? '  [DRY RUN]' : ' ';

        $summary = sprintf(
            '%s %s: %d total (%d created, %d updated, %d deleted, %d skipped, %d errors)',
            $prefix,
            $entity,
            $stats['total'],
            $stats['created'],
            $stats['updated'],
            $stats['deleted'],
            $stats['skipped'],
            $stats['errors']
        );

        if ($hasError) {
            $this->error($summary);
            $this->error("    → {$stats['error_message']}");
        } elseif ($stats['total'] > 0) {
            $this->info($summary);
        } else {
            $this->line($summary);
        }
    }

    /**
     * Отображение полной статистики
     */
    protected function displayAllStats(
        SyncChangeProcessor $pushProcessor,
        Bitrix24PullService $pullService
    ): void {
        $this->info('📊 Bitrix24 Synchronization Statistics');
        $this->newLine();

        // === PUSH Queue (Laravel → B24) ===
        $this->info('🚀 PUSH Queue (Laravel → B24):');
        $pushStats = $pushProcessor->getQueueStats();
        $this->table(
            ['Status', 'Count'],
            [
                ['Pending', $pushStats['pending']],
                ['Retry', $pushStats['retry']],
                ['Processing', $pushStats['processing']],
                ['Error', $pushStats['error']],
                ['Locked', $pushStats['locked']],
                ['─────────', '─────'],
                ['Ready to process', $pushStats['total_ready']],
            ]
        );
        $this->newLine();

        // === PULL State (B24 → Laravel) ===
        $this->info('🔽 PULL State (B24 → Laravel):');
        $pullStats = $pullService->getStats();

        $tableData = [];
        foreach ($pullStats as $entity => $state) {
            $lastSync = $state['last_sync_at'] ?? null;
            $lastB24Update = $state['last_b24_updated_at'] ?? null;

            $tableData[] = [
                $entity,
                $lastSync ? date('Y-m-d H:i:s', strtotime($lastSync)) : 'Never',
                $lastB24Update ? date('Y-m-d H:i:s', strtotime($lastB24Update)) : 'N/A',
            ];
        }

        if (!empty($tableData)) {
            $this->table(
                ['Entity', 'Last Sync', 'Last B24 Update'],
                $tableData
            );
        } else {
            $this->line('  No sync history yet');
        }
    }
}
