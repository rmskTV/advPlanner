<?php

namespace Modules\Bitrix24\app\Services\Pull;

use AllowDynamicProperties;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\CustomerOrder;
use Modules\Accounting\app\Models\Product;
use Modules\Bitrix24\app\Models\B24SyncState;
use Modules\Bitrix24\app\Services\Mappers\B24InvoiceMapper;

#[AllowDynamicProperties] class InvoicePuller extends AbstractPuller
{
    const INVOICE_ENTITY_TYPE_ID = 31; // SmartInvoice

    protected array $productGuidCache = [];

    /**
     * Сервис для синхронизации зависимостей
     */
    protected ?DependencySyncService $dependencyService = null;

    protected function getEntityType(): string
    {
        return B24SyncState::ENTITY_INVOICE;
    }

    protected function getB24Method(): string
    {
        return 'crm.item';
    }

    protected function getSelectFields(): array
    {
        return [
            'id',
            'title',
            'xmlId',
            'createdTime',
            'updatedTime',
            'begindate',
            'companyId',
            'contactId',
            'mycompanyId',
            'assignedById',
            'opportunity',
            'currencyId',
            'isManualOpportunity',
            'comments',
            'parentId1064', // ID договора (SPA 1064)
            'ufCrm_SMART_INVOICE_LAST_UPDATE_FROM_1C',
        ];
    }

    protected function getGuid1CFieldName(): string
    {
        return 'xmlId';
    }

    protected function getLastUpdateFrom1CFieldName(): string
    {
        return 'ufCrm_SMART_INVOICE_LAST_UPDATE_FROM_1C';
    }

    /**
     * Получить или создать сервис зависимостей
     */
    protected function getDependencyService(): DependencySyncService
    {
        if (!$this->dependencyService) {
            $this->dependencyService = new DependencySyncService($this->b24Service);
            $this->dependencyService->setDryRun($this->dryRun);

            if ($this->output) {
                $this->dependencyService->setOutput($this->output);
            }
        }

        return $this->dependencyService;
    }

    /**
     * Переопределяем обработку - сначала синхронизируем зависимости
     * @throws \Exception
     */
    protected function processItem(array $b24Item): array
    {
        $b24Id = $this->extractB24Id($b24Item);

        // 1. Проверяем фильтр shouldImport
        if (!$this->shouldImport($b24Item)) {
            if ($this->output) {
                $this->output->line("    ⊘ Skipped (not modified since 1C sync): B24 ID {$b24Id}");
            }
            return ['action' => 'skipped'];
        }

        // 2. Синхронизируем зависимости и ПОЛУЧАЕМ ИХ GUID
        $dependencyResult = $this->ensureDependencies($b24Item);

        Log::info('=== INVOICE DEPENDENCIES ===', [
            'invoice_id' => $b24Id,
            'company_id' => $b24Item['companyId'] ?? null,
            'parent_id_1064' => $b24Item['parentId1064'] ?? null,
            'resolved_counterparty' => $dependencyResult['counterparty_guid'],
            'resolved_contract' => $dependencyResult['contract_guid'],
            'success' => $dependencyResult['success'],
        ]);

        if (!$dependencyResult['success']) {
            Log::warning('Invoice dependencies not ready', [
                'invoice_b24_id' => $b24Id,
                'missing' => $dependencyResult['missing'],
            ]);

            if ($this->output) {
                $this->output->line("    ⚠ Dependencies not ready: " . implode(', ', $dependencyResult['missing']));
            }

            return ['action' => 'skipped', 'reason' => 'dependencies_not_ready'];
        }

        // 3. 🆕 Сохраняем GUID-ы для использования в маппере
        $this->resolvedDependencies = [
            'counterparty_guid' => $dependencyResult['counterparty_guid'],
            'contract_guid' => $dependencyResult['contract_guid'],
        ];

        // 4. Вызываем родительский processItem (там вызовется mapToLocal)
        $result = parent::processItem($b24Item);

        // 5. Синхронизируем строки счёта
        if (!$this->dryRun && in_array($result['action'], ['created', 'updated'])) {
            $localModel = $this->findOrCreateLocal($b24Id);

            if ($localModel->exists) {
                $this->syncInvoiceItems($b24Id, $localModel);
            }
        }

        // Очищаем после использования
        $this->resolvedDependencies = [];

        return $result;
    }

    /**
     * Убедиться, что все зависимости счёта синхронизированы
     *
     * @return array [
     *   'success' => bool,
     *   'missing' => array,
     *   'counterparty_guid' => string|null,
     *   'contract_guid' => string|null,
     * ]
     */
    protected function ensureDependencies(array $b24Item): array
    {
        $missing = [];
        $dependencyService = $this->getDependencyService();

        $counterpartyGuid = null;
        $contractGuid = null;

        // 1. Контрагент (ОБЯЗАТЕЛЬНО)
        $companyId = $b24Item['companyId'] ?? null;

        if ($companyId) {
            $counterpartyGuid = $dependencyService->ensureCounterparty((int) $companyId);

            if (!$counterpartyGuid) {
                $missing[] = "counterparty (company_id: {$companyId})";
            }
        } else {
            $missing[] = 'counterparty (no companyId in invoice)';
        }

        // 2. Договор (ОПЦИОНАЛЬНО, но пытаемся получить)
        $contractId = $b24Item['parentId1064'] ?? null;

        if ($contractId) {
            $contractGuid = $dependencyService->ensureContract((int) $contractId);

            if (!$contractGuid) {
                Log::info('Contract not synced for invoice', [
                    'invoice_id' => $b24Item['id'] ?? null,
                    'contract_id' => $contractId,
                ]);
                // НЕ добавляем в missing — договор опционален
            }
        }

        return [
            'success' => empty($missing),
            'missing' => $missing,
            'counterparty_guid' => $counterpartyGuid,
            'contract_guid' => $contractGuid,
        ];
    }

    /**
     * Переопределяем маппер, чтобы он не выбрасывал DependencyNotReadyException
     * (зависимости уже синхронизированы на предыдущем шаге)
     */

    protected function mapToLocal(array $b24Item): array
    {
        $mapper = new B24InvoiceMapper($this->b24Service);
        $mapper->setStrictMode(false);

        // 🆕 Передаём уже разрешённые зависимости!
        if (!empty($this->resolvedDependencies)) {
            $mapper->setResolvedDependencies($this->resolvedDependencies);
        }

        return $mapper->map($b24Item);
    }

    /**
     * Переопределяем fetchChangedItems
     */
    protected function fetchChangedItems(?\Carbon\Carbon $lastSync): array
    {
        $filter = [];

        if ($lastSync) {
            $adjustedTime = (clone $lastSync)->modify('+8 hours');
            $filter['>updatedTime'] = $adjustedTime->format('Y-m-d\TH:i:s') . 'C';
        }

        $response = $this->b24Service->call('crm.item.list', [
            'entityTypeId' => self::INVOICE_ENTITY_TYPE_ID,
            'filter' => $filter,
            'select' => $this->getSelectFields(),
            'order' => ['updatedTime' => 'ASC'],
        ]);

        return $response['result']['items'] ?? [];
    }

    protected function extractGuid1C(array $b24Item): ?string
    {
        return !empty($b24Item['xmlId']) ? $b24Item['xmlId'] : null;
    }

    protected function shouldImport(array $b24Item): bool
    {
        $lastUpdateFrom1C = $this->extractLastUpdateFrom1C($b24Item);
        $dateModify = $this->parseB24DateTime($b24Item['updatedTime']);

        if (!$dateModify) {
            return false;
        }

        if (!$lastUpdateFrom1C) {
            return true;
        }

        return $lastUpdateFrom1C < $dateModify;
    }

    protected function getLatestUpdateTime(array $items): \Carbon\Carbon
    {
        $latest = null;

        foreach ($items as $item) {
            $time = $this->parseB24DateTime($item['updatedTime']);
            if ($time && (!$latest || $time > $latest)) {
                $latest = $time;
            }
        }

        return $latest ?? now();
    }

    protected function updateGuidInB24(int $b24Id, string $guid): void
    {
        try {
            $this->b24Service->call('crm.item.update', [
                'entityTypeId' => self::INVOICE_ENTITY_TYPE_ID,
                'id' => $b24Id,
                'fields' => [
                    'xmlId' => $guid,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update GUID in B24 invoice', [
                'b24_id' => $b24Id,
                'guid' => $guid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function syncInvoiceItems(int $invoiceB24Id, CustomerOrder $order): void
    {
        try {
            $response = $this->b24Service->call('crm.item.productrow.list', [
                'filter' => [
                    '=ownerId' => $invoiceB24Id,
                    '=ownerType' => 'SI',
                ],
            ]);

            $productRows = $response['result']['productRows'] ?? [];

            if (empty($productRows)) {
                return;
            }

            $order->items()->delete();

            foreach ($productRows as $index => $row) {
                $quantity = (float) ($row['quantity'] ?? 1);
                $price = (float) ($row['price'] ?? 0);
                $amount = $quantity * $price;

                $order->items()->create([
                    'line_number' => $index + 1,
                    'product_guid_1c' => $this->findProductGuidByB24Id($row['productId'] ?? null),
                    'product_name' => $row['productName'] ?? 'Товар/Услуга',
                    'quantity' => $quantity,
                    'unit_guid_1c' => $this->mapMeasureCodeToGuid($row['measureCode'] ?? null),
                    'unit_name' => $row['measureName'] ?? null,
                    'price' => $price,
                    'amount' => $amount,
                    'vat_amount' => $this->calculateVatAmount($row),
                    'content' => $row['productName'] ?? null,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to sync invoice items', [
                'invoice_b24_id' => $invoiceB24Id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function findProductGuidByB24Id(?int $productId): ?string
    {
        if (!$productId) {
            return null;
        }

        if (isset($this->productGuidCache[$productId])) {
            return $this->productGuidCache[$productId];
        }

        $product = Product::where('b24_id', $productId)->first();

        if ($product && $product->guid_1c) {
            $this->productGuidCache[$productId] = $product->guid_1c;
            return $product->guid_1c;
        }

        // Если товар не найден локально - можно добавить ensureProduct()
        // Пока возвращаем null
        $this->productGuidCache[$productId] = null;
        return null;
    }

    protected function mapMeasureCodeToGuid(?int $measureCode): ?string
    {
        if (!$measureCode) {
            return null;
        }

        $unit = \Modules\Accounting\app\Models\UnitOfMeasure::where('code', (string) $measureCode)->first();

        return $unit?->guid_1c;
    }

    protected function calculateVatAmount(array $row): float
    {
        $quantity = $row['quantity'] ?? 1;
        $price = $row['price'] ?? 0;
        $taxRate = $row['taxRate'] ?? 0;
        $taxIncluded = ($row['taxIncluded'] ?? 'N') === 'Y';

        $amount = $quantity * $price;

        if ($taxRate <= 0) {
            return 0;
        }

        if ($taxIncluded) {
            return $amount * $taxRate / (100 + $taxRate);
        } else {
            return $amount * $taxRate / 100;
        }
    }

    protected function findOrCreateLocal(int $b24Id)
    {
        return CustomerOrder::firstOrNew(['b24_id' => $b24Id]);
    }

    protected function getModelClass(): string
    {
        return CustomerOrder::class;
    }
}
