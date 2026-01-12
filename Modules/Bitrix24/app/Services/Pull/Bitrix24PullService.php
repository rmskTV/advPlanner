<?php

namespace Modules\Bitrix24\app\Services\Pull;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Bitrix24\app\Models\B24SyncState;
use Modules\Bitrix24\app\Services\Bitrix24Service;

class Bitrix24PullService
{
    protected Bitrix24Service $b24Service;

    protected array $pullers = [
        'Requisite' => RequisitePuller::class,
        'Contact' => ContactPuller::class,
        'Contract' => ContractPuller::class,
        'Product' => ProductPuller::class,
        'Invoice' => InvoicePuller::class,
    ];

    public function __construct(Bitrix24Service $b24Service)
    {
        $this->b24Service = $b24Service;
    }

    /**
     * Запустить импорт всех сущностей
     */
    public function pullAll(bool $dryRun = false, ?Command $output = null): array
    {
        $entities = array_keys($this->pullers);
        $allStats = [];

        Log::info('Starting full B24 pull', [
            'entities' => $entities,
            'dry_run' => $dryRun,
        ]);

        foreach ($entities as $entityType) {
            Log::info("Pulling {$entityType}...");

            try {
                $stats = $this->pullEntity($entityType, $dryRun, $output);
                $allStats[$entityType] = $stats;

                Log::info("Completed {$entityType}", $stats);

            } catch (\Exception $e) {
                Log::error("Failed to pull {$entityType}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $allStats[$entityType] = [
                    'total' => 0,
                    'created' => 0,
                    'updated' => 0,
                    'skipped' => 0,
                    'deleted' => 0,
                    'errors' => 1,
                    'error_message' => $e->getMessage(),
                ];
            }
        }

        Log::info('B24 pull completed', ['summary' => $allStats]);

        return $allStats;
    }

    /**
     * Импорт конкретной сущности
     */
    public function pullEntity(string $entityType, bool $dryRun = false, ?Command $output = null): array
    {
        if (!isset($this->pullers[$entityType])) {
            throw new \InvalidArgumentException("Unknown entity type: {$entityType}");
        }

        $pullerClass = $this->pullers[$entityType];
        $puller = new $pullerClass($this->b24Service);

        // Устанавливаем режим dry-run
        $puller->setDryRun($dryRun);

        // Устанавливаем output для verbose режима
        if ($output) {
            $puller->setOutput($output);
        }

        return $puller->pull();
    }

    public function getSupportedEntities(): array
    {
        return array_keys($this->pullers);
    }

    public function getStats(): array
    {
        return B24SyncState::getAllStates();
    }
}
