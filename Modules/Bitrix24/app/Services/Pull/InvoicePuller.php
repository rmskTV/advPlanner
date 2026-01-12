<?php

namespace Modules\Bitrix24\app\Services\Pull;

use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\CustomerOrder;
use Modules\Bitrix24\app\Models\B24SyncState;
use Modules\Bitrix24\app\Services\Mappers\B24InvoiceMapper;

class InvoicePuller extends AbstractPuller
{
    const INVOICE_ENTITY_TYPE_ID = 31; // SmartInvoice

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

            // Связь с договором
            'parentId1064', // ID договора (SPA 1064)

            // Кастомные поля
            'ufCrmSmartInvoiceLastUpdateFrom1c', // Время обновления из 1С
            'ufCrm_SMART_INVOICE_LAST_UPDATE_FROM_1C',
        ];
    }

    protected function getGuid1CFieldName(): string
    {
        return 'xmlId'; // Для счетов GUID хранится в xmlId
    }

    protected function getLastUpdateFrom1CFieldName(): string
    {
        return 'ufCrm_SMART_INVOICE_LAST_UPDATE_FROM_1C';
    }

    /**
     * Переопределяем получение данных - используем crm.item.list для счетов
     */
    protected function fetchChangedItems(?\Carbon\Carbon $lastSync): array
    {
        $filter = [];

        if ($lastSync) {
            $filter['>updatedTime'] = $lastSync->format('Y-m-d\TH:i:sP');
        }

        $response = $this->b24Service->call('crm.item.list', [
            'entityTypeId' => self::INVOICE_ENTITY_TYPE_ID,
            'filter' => $filter,
            'select' => $this->getSelectFields(),
            'order' => ['updatedTime' => 'ASC'],
        ]);

        return $response['result']['items'] ?? [];
    }

    /**
     * Для счетов xmlId = GUID
     */
    protected function extractGuid1C(array $b24Item): ?string
    {
        return !empty($b24Item['xmlId']) ? $b24Item['xmlId'] : null;
    }

    /**
     * Для счетов поле updatedTime вместо DATE_MODIFY
     */
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

    /**
     * Получить максимальное время обновления
     */
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

    /**
     * Обновить GUID в счете
     */
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

            Log::debug('GUID updated in B24 invoice', [
                'b24_id' => $b24Id,
                'guid' => $guid,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update GUID in B24 invoice', [
                'b24_id' => $b24Id,
                'guid' => $guid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Переопределяем обработку - нужно синхронизировать и строки
     */
    protected function processItem(array $b24Item): array
    {
        // 1. Базовая обработка заказа
        $result = parent::processItem($b24Item);

        // 2. Если заказ успешно обработан - синхронизируем строки (только в обычном режиме!)
        if (!$this->dryRun && in_array($result['action'], ['created', 'updated'])) {
            $b24Id = $this->extractB24Id($b24Item);
            $localModel = $this->findOrCreateLocal($b24Id);

            if ($localModel->exists) {
                $this->syncInvoiceItems($b24Id, $localModel);
            }
        }

        return $result;
    }

    /**
     * Синхронизация товарных позиций счета
     */
    protected function syncInvoiceItems(int $invoiceB24Id, CustomerOrder $order): void
    {
        try {
            // Получаем товарные позиции из B24
            $response = $this->b24Service->call('crm.item.productrow.get', [
                'ownerType' => 'SI', // SmartInvoice
                'ownerId' => $invoiceB24Id,
            ]);

            $productRows = $response['result']['productRows'] ?? [];

            if (empty($productRows)) {
                Log::debug('No product rows for invoice', ['invoice_b24_id' => $invoiceB24Id]);
                return;
            }

            // Удаляем старые строки
            $order->items()->delete();

            // Создаём новые
            foreach ($productRows as $index => $row) {
                $order->items()->create([
                    'line_number' => $index + 1,
                    'product_guid_1c' => $this->findProductGuidByB24Id($row['productId'] ?? null),
                    'product_name' => $row['productName'] ?? 'Товар/Услуга',
                    'quantity' => $row['quantity'] ?? 1,
                    'unit_guid_1c' => $this->mapMeasureCodeToGuid($row['measureCode'] ?? null),
                    'unit_name' => $row['measureName'] ?? null,
                    'price' => $row['price'] ?? 0,
                    'amount' => ($row['quantity'] ?? 1) * ($row['price'] ?? 0),
                    'vat_amount' => $this->calculateVatAmount($row),
                    'content' => $row['productName'] ?? null,
                ]);
            }

            Log::info('Invoice items synced', [
                'order_id' => $order->id,
                'items_count' => count($productRows),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to sync invoice items', [
                'invoice_b24_id' => $invoiceB24Id,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Найти GUID товара по B24 ID
     */
    protected function findProductGuidByB24Id(?int $productId): ?string
    {
        if (!$productId) {
            return null;
        }

        $product = \Modules\Accounting\app\Models\Product::where('b24_id', $productId)->first();

        return $product?->guid_1c;
    }

    /**
     * Маппинг кода единицы измерения → GUID
     */
    protected function mapMeasureCodeToGuid(?int $measureCode): ?string
    {
        if (!$measureCode) {
            return null;
        }

        $unit = \Modules\Accounting\app\Models\UnitOfMeasure::where('code', (string) $measureCode)->first();

        return $unit?->guid_1c;
    }

    /**
     * Расчёт суммы НДС из строки
     */
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
            // НДС включён в цену
            return $amount * $taxRate / (100 + $taxRate);
        } else {
            // НДС сверху
            return $amount * $taxRate / 100;
        }
    }

    protected function mapToLocal(array $b24Item): array
    {
        $mapper = new B24InvoiceMapper($this->b24Service);
        return $mapper->map($b24Item);
    }

    protected function findOrCreateLocal(int $b24Id)
    {
        return CustomerOrder::firstOrNew(['b24_id' => $b24Id]);
    }

    /**
     * Получить класс модели для поиска
     * Должен быть переопределён в наследниках
     */
    protected function getModelClass(): string
    {
        return CustomerOrder::class;
    }
}
