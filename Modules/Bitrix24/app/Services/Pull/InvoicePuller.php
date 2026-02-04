<?php

namespace Modules\Bitrix24\app\Services\Pull;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\CustomerOrder;
use Modules\Accounting\app\Models\Product;
use Modules\Bitrix24\app\Models\B24SyncState;
use Modules\Bitrix24\app\Services\Mappers\B24InvoiceMapper;

class InvoicePuller extends AbstractPuller
{
    const INVOICE_ENTITY_TYPE_ID = 31; // SmartInvoice

    protected array $productGuidCache = [];

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
            /*
             * WORKAROUND: Баг Битрикс24 REST API (crm.item.list)
             *
             * При фильтрации по полю updatedTime Битрикс24 игнорирует указание
             * часового пояса (суффиксы Z, +03:00 и т.д.) и сравнивает только
             * datetime-часть как "наивное" время.
             *
             * Пример проблемы:
             *   Фильтр: filter[>updatedTime]=2026-01-07T13:29:13Z (UTC)
             *   Запись: updatedTime: "2026-01-07T11:29:15+03:00" (= 08:29:15 UTC)
             *   Ожидание: запись НЕ попадёт (08:29 < 13:29 в UTC)
             *   Реальность: запись попадает, т.к. Б24 сравнивает 11:29 vs 13:29
             *
             * Решение: вручную пересчитываем время — добавляем 8 часов смещения
             * и суффикс 'C' для корректной фильтрации на стороне Б24.
             *
             * @see https://idea.1c-bitrix.ru/ — если баг будет исправлен, этот
             *      workaround нужно будет убрать
             */
            $adjustedTime = (clone $lastSync)->modify('+8 hours');
            $filter['>updatedTime'] = $adjustedTime->format('Y-m-d\TH:i:s') . 'C';
            Log::info($adjustedTime->format('Y-m-d\TH:i:s') . 'C');
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
     * @throws \Exception
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
            Log::info('Starting to sync invoice items', [
                'invoice_b24_id' => $invoiceB24Id,
                'order_id' => $order->id,
            ]);

            $response = $this->b24Service->call('crm.item.productrow.list', [
                'filter' => [
                    '=ownerId' => $invoiceB24Id,
                    '=ownerType' => 'SI',
                ],
            ]);

            // ✅ ИСПРАВЛЕНО: извлекаем из result.productRows
            $productRows = $response['result']['productRows'] ?? [];

            if (empty($productRows)) {
                Log::warning('No product rows for invoice', [
                    'invoice_b24_id' => $invoiceB24Id,
                    'response_structure' => json_encode($response),
                ]);
                return;
            }

            Log::info('Found product rows', [
                'invoice_b24_id' => $invoiceB24Id,
                'count' => count($productRows),
            ]);

            // Удаляем старые строки
            $order->items()->delete();

            // Создаём новые
            foreach ($productRows as $index => $row) {
                // ✅ ИСПРАВЛЕНО: явное приведение типов
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

                Log::debug('Invoice item created', [
                    'line_number' => $index + 1,
                    'product_name' => $row['productName'] ?? 'N/A',
                    'quantity' => $quantity,
                    'price' => $price,
                    'amount' => $amount,
                ]);
            }

            Log::info('Invoice items synced successfully', [
                'order_id' => $order->id,
                'items_count' => count($productRows),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to sync invoice items', [
                'invoice_b24_id' => $invoiceB24Id,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Пробрасываем исключение дальше, чтобы транзакция откатилась
            throw $e;
        }
    }


    /**
     * Найти GUID товара по B24 ID
     *
     * Логика:
     * 1. Проверяем кэш
     * 2. Ищем локально по b24_id
     * 3. Запрашиваем из B24 и извлекаем GUID из свойства
     * 4. Обновляем локальную запись если нашли по GUID
     */
    protected function findProductGuidByB24Id(?int $productId): ?string
    {
        if (!$productId) {
            return null;
        }

        // 1. Проверяем кэш
        if (isset($this->productGuidCache[$productId])) {
            return $this->productGuidCache[$productId];
        }

        // 2. Ищем локально по b24_id
        $product = Product::where('b24_id', $productId)->first();

        if ($product && $product->guid_1c) {
            $this->productGuidCache[$productId] = $product->guid_1c;
            return $product->guid_1c;
        }

        // 3. Запрашиваем из B24
        try {
            Log::debug('Fetching product from B24', ['product_id' => $productId]);

            // Получаем ID свойства GUID_1C
            $propertyIds = $this->getProductPropertyIds();

            if (!isset($propertyIds['GUID_1C'])) {
                Log::warning('GUID_1C property not found for products');
                $this->productGuidCache[$productId] = null;
                return null;
            }

            $guidPropertyId = $propertyIds['GUID_1C'];

            // Запрашиваем товар
            $response = $this->b24Service->call('crm.product.get', [
                'id' => $productId,
            ]);

            if (empty($response['result'])) {
                Log::warning('Product not found in B24', ['product_id' => $productId]);
                $this->productGuidCache[$productId] = null;
                return null;
            }

            $b24Product = $response['result'];

            // Извлекаем GUID из свойства
            $propertyKey = 'PROPERTY_' . $guidPropertyId;
            $guid = null;

            if (isset($b24Product[$propertyKey])) {
                $value = $b24Product[$propertyKey];

                // Свойство может быть массивом
                if (is_array($value)) {
                    $guid = $value['value'] ?? null;
                } else {
                    $guid = $value;
                }
            }

            if (empty($guid)) {
                Log::info('Product has no GUID in B24', ['product_id' => $productId]);
                $this->productGuidCache[$productId] = null;
                return null;
            }

            $guid = (string) $guid;

            // 4. Обновляем локальную запись если нашли по GUID
            $localProduct = Product::where('guid_1c', $guid)->first();

            if ($localProduct) {
                // Привязываем b24_id к существующему товару
                if (!$localProduct->b24_id) {
                    $localProduct->b24_id = $productId;
                    $localProduct->save();

                    Log::info('Linked B24 product to local', [
                        'local_id' => $localProduct->id,
                        'b24_id' => $productId,
                        'guid' => $guid,
                    ]);
                }
            } else {
                Log::info('Product exists in B24 but not in local DB', [
                    'b24_id' => $productId,
                    'guid' => $guid,
                ]);
            }

            // Кэшируем
            $this->productGuidCache[$productId] = $guid;

            return $guid;

        } catch (\Exception $e) {
            Log::error('Failed to fetch product from B24', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);

            $this->productGuidCache[$productId] = null;
            return null;
        }
    }
    /**
     * Получить ID свойств товара (с кэшированием)
     */
    protected function getProductPropertyIds(): array
    {
        return Cache::remember('b24:product_properties', 3600, function () {
            $response = $this->b24Service->call('crm.product.property.list');

            $properties = [];
            foreach ($response['result'] ?? [] as $property) {
                if (!empty($property['CODE'])) {
                    $properties[$property['CODE']] = (int) $property['ID'];
                }
            }

            return $properties;
        });
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
