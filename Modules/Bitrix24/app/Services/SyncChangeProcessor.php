<?php
// Modules/Bitrix24/app/Services/SyncChangeProcessor.php

namespace Modules\Bitrix24\app\Services;

use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\ObjectChangeLog;
use Modules\Bitrix24\app\Services\Processors\CompanySyncProcessor;
use Modules\Bitrix24\app\Services\Processors\ContactSyncProcessor;
use Modules\Bitrix24\app\Services\Processors\ContractSyncProcessor;
use Modules\Bitrix24\app\Services\Processors\CustomerOrderSyncProcessor;
use Modules\Bitrix24\app\Services\Processors\OrderPaymentStatusSyncProcessor;
use Modules\Bitrix24\app\Services\Processors\OrganizationSyncProcessor;
use Modules\Bitrix24\app\Services\Processors\ProductSyncProcessor;

class SyncChangeProcessor
{
    protected Bitrix24Service $b24Service;
    protected bool $shouldStop = false;
    protected int $processedCount = 0;
    protected int $errorCount = 0;
    protected int $skippedCount = 0;

    // Карта типов сущностей → процессоры
    protected array $processors = [
        'Modules\Accounting\app\Models\Counterparty' => CompanySyncProcessor::class,
        'Modules\Accounting\app\Models\ContactPerson' => ContactSyncProcessor::class,
        'Modules\Accounting\app\Models\Contract' => ContractSyncProcessor::class,
        'Modules\Accounting\app\Models\Organization' => OrganizationSyncProcessor::class,
        'Modules\Accounting\app\Models\CustomerOrder' => CustomerOrderSyncProcessor::class,
        'Modules\Accounting\app\Models\OrderPaymentStatus' => OrderPaymentStatusSyncProcessor::class,
        'Modules\Accounting\app\Models\Product' => ProductSyncProcessor::class,
    ];

    public function __construct(Bitrix24Service $b24Service)
    {
        $this->b24Service = $b24Service;
        $this->registerSignalHandlers();
    }

    /**
     * Главный метод обработки очереди
     *
     * @param int|null $limit Максимальное количество записей (null = обработать всё)
     * @param callable|null $progressCallback Коллбэк для прогресса (для команды)
     */
    public function process(?int $limit = null, ?callable $progressCallback = null): array
    {
        $this->resetCounters();

        $chunkSize = config('bitrix24.sync.chunk_size', 100);
        $totalProcessed = 0;

        Log::info("Starting sync process", ['limit' => $limit ?? 'unlimited']);

        while (!$this->shouldStop) {
            // Получаем порцию записей с фильтрацией
            $changes = ObjectChangeLog::readyForProcessing(
                supportedEntityTypes: array_keys($this->processors), // ← Фильтруем на уровне БД
                source: '1C' // ← Только из 1С
            )
                ->orderBy('created_at', 'asc')
                ->limit($chunkSize)
                ->get();

            // Если записей нет - выходим
            if ($changes->isEmpty()) {
                Log::debug("No more changes to process");
                break;
            }

            Log::debug("Processing chunk", ['size' => $changes->count()]);

            // Обрабатываем порцию
            foreach ($changes as $change) {
                // Проверка лимита
                if ($limit !== null && $totalProcessed >= $limit) {
                    Log::info("Reached processing limit", ['limit' => $limit]);
                    $this->shouldStop = true;
                    break;
                }

                // Обрабатываем (фильтрация уже на уровне SQL)
                try {
                    $this->processChange($change);
                    $this->processedCount++;
                    $totalProcessed++;

                    // Коллбэк для прогресс-бара
                    if ($progressCallback) {
                        $progressCallback($change);
                    }

                } catch (\Exception $e) {
                    // Ошибки уже залогированы в AbstractBitrix24Processor
                    $this->errorCount++;
                    Log::error("Unhandled error in processor", [
                        'change_id' => $change->id,
                        'error' => $e->getMessage()
                    ]);
                }

                // Throttling
                $this->throttle();
            }

            // Если обработали меньше чем chunk - значит записей больше нет
            if ($changes->count() < $chunkSize) {
                Log::debug("Processed last chunk");
                break;
            }

            // Проверка на graceful shutdown
            if ($this->shouldStop) {
                Log::warning("Graceful shutdown initiated");
                break;
            }
        }

        $stats = [
            'processed' => $this->processedCount,
            'errors' => $this->errorCount,
            'skipped' => $this->skippedCount,
            'total' => $this->processedCount + $this->errorCount + $this->skippedCount
        ];

        Log::info("Sync process completed", $stats);

        return $stats;
    }


    /**
     * Обработка одной записи
     */
    protected function processChange(ObjectChangeLog $change): void
    {
        $processorClass = $this->processors[$change->entity_type];
        $processor = new $processorClass($this->b24Service);
        $processor->process($change);
    }

    /**
     * Throttling для соблюдения rate limits
     */
    protected function throttle(): void
    {
        $requestsPerSecond = config('bitrix24.sync.requests_per_second', 1);
        $delayMicroseconds = (int)(1_000_000 / $requestsPerSecond);

        usleep($delayMicroseconds);
    }

    /**
     * Разблокировка зависших записей
     */
    public function unlockStaleRecords(?int $minutes = null): int
    {
        $minutes = $minutes ?? config('bitrix24.sync.stale_timeout_minutes', 10);

        $staleRecords = ObjectChangeLog::stale($minutes)->get();

        if ($staleRecords->isEmpty()) {
            return 0;
        }

        Log::warning("Found stale locked records", ['count' => $staleRecords->count()]);

        foreach ($staleRecords as $record) {
            $record->unlock();

            if ($record->retry_count < ObjectChangeLog::MAX_RETRIES) {
                $record->markRetry("Unlocked after {$minutes}min timeout");
            } else {
                $record->markError("Max retries exceeded after timeout");
            }
        }

        return $staleRecords->count();
    }

    /**
     * Регистрация обработчиков сигналов для graceful shutdown
     */
    protected function registerSignalHandlers(): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, function () {
            Log::info("Received SIGTERM, stopping gracefully...");
            $this->shouldStop = true;
        });

        pcntl_signal(SIGINT, function () {
            Log::info("Received SIGINT (Ctrl+C), stopping gracefully...");
            $this->shouldStop = true;
        });
    }

    /**
     * Сброс счётчиков
     */
    protected function resetCounters(): void
    {
        $this->processedCount = 0;
        $this->errorCount = 0;
        $this->skippedCount = 0;
        $this->shouldStop = false;
    }

    /**
     * Получение статистики по очереди
     */
    public function getQueueStats(): array
    {
        $supportedTypes = array_keys($this->processors);

        return [
            'pending' => ObjectChangeLog::where('status', 'pending')->where('source', '1C')->count(),
            'retry' => ObjectChangeLog::where('status', 'retry')->where('source', '1C')->count(),
            'processing' => ObjectChangeLog::where('status', 'processing')->where('source', '1C')->count(),
            'error' => ObjectChangeLog::where('status', 'error')->where('source', '1C')->count(),
            'skipped' => ObjectChangeLog::where('status', 'skipped')->where('source', '1C')->count(),
            'locked' => ObjectChangeLog::whereNotNull('locked_at')->count(),
            'total_ready' => ObjectChangeLog::readyForProcessing($supportedTypes, '1C')->count(),
        ];
    }

    /**
     * Получение списка поддерживаемых типов
     */
    public function getSupportedTypes(): array
    {
        return array_keys($this->processors);
    }
}
