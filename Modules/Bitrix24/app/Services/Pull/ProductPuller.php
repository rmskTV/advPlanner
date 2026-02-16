<?php

namespace Modules\Bitrix24\app\Services\Pull;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\Product;
use Modules\Bitrix24\app\Models\B24SyncState;
use Modules\Bitrix24\app\Services\Mappers\B24ProductMapper;

class ProductPuller extends AbstractPuller
{
    protected function getEntityType(): string
    {
        return B24SyncState::ENTITY_PRODUCT;
    }

    protected function getB24Method(): string
    {
        return 'crm.product';
    }

    protected function getSelectFields(): array
    {
        return [
            'ID',
            'NAME',
            'CODE',
            'DESCRIPTION',
            'PRICE',
            'CURRENCY_ID',
            'MEASURE',
            'VAT_ID',
            'VAT_INCLUDED',
            'SECTION_ID',          // ID группы/секции
            'SORT',
            'ACTIVE',
            'DATE_CREATE',
            'TIMESTAMP_X',

            // Кастомные свойства (нужно получить ID свойств)
            // Будем получать через property.list отдельно
        ];
    }

    protected function getGuid1CFieldName(): string
    {
        return 'PROPERTY_GUID_1C'; // Это будет PROPERTY_{ID}, получим динамически
    }

    protected function getLastUpdateFrom1CFieldName(): string
    {
        return 'PROPERTY_LAST_UPDATE_FROM_1C'; // Аналогично
    }

    /**
     * Переопределяем для товаров - нужны кастомные свойства
     */
    protected function fetchChangedItems(?\Carbon\Carbon $lastSync): array
    {
        // Получаем ID свойств
        $propertyIds = $this->getProductPropertyIds();

        // Добавляем свойства в select
        $select = $this->getSelectFields();

        if (isset($propertyIds['GUID_1C'])) {
            $select[] = 'PROPERTY_' . $propertyIds['GUID_1C'];
        }

        if (isset($propertyIds['LAST_UPDATE_FROM_1C'])) {
            $select[] = 'PROPERTY_' . $propertyIds['LAST_UPDATE_FROM_1C'];
        }

        if (isset($propertyIds['ANALYTICS_GROUP'])) {
            $select[] = 'PROPERTY_' . $propertyIds['ANALYTICS_GROUP'];
        }

        if (isset($propertyIds['ANALYTICS_GROUP_GUID'])) {
            $select[] = 'PROPERTY_' . $propertyIds['ANALYTICS_GROUP_GUID'];
        }

        $filter = [];

        if ($lastSync) {
            $filter['>TIMESTAMP_X'] = $lastSync->format('Y-m-d\TH:i:sP');
        }

        $response = $this->b24Service->call($this->getB24Method() . '.list', [
            'filter' => $filter,
            'select' => $select,
            'order' => ['TIMESTAMP_X' => 'ASC'],
        ]);

        return $response['result'] ?? [];
    }

    /**
     * Извлечь GUID из свойств товара
     */
    protected function extractGuid1C(array $b24Item): ?string
    {
        $propertyIds = $this->getProductPropertyIds();

        if (!isset($propertyIds['GUID_1C'])) {
            return null;
        }

        $propertyKey = 'PROPERTY_' . $propertyIds['GUID_1C'];

        // Безопасная проверка наличия ключа
        if (!array_key_exists($propertyKey, $b24Item)) {
            return null;
        }

        $value = $b24Item[$propertyKey];

        // В B24 свойства могут быть массивами
        if (is_array($value)) {
            return $value['value'] ?? null;
        }

        return !empty($value) ? (string) $value : null;
    }


    /**
     * Извлечь last_update_from_1c
     */
    protected function extractLastUpdateFrom1C(array $b24Item): ?\Carbon\Carbon
    {
        $propertyIds = $this->getProductPropertyIds();

        if (!isset($propertyIds['LAST_UPDATE_FROM_1C'])) {
            return null;
        }

        $propertyKey = 'PROPERTY_' . $propertyIds['LAST_UPDATE_FROM_1C'];

        if (!array_key_exists($propertyKey, $b24Item)) {
            return null;
        }

        $value = $b24Item[$propertyKey];

        // В B24 свойства могут быть массивами
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (empty($value)) {
            return null;
        }

        return $this->parseB24DateTime($value);
    }
    /**
     * Обновить GUID в свойствах товара
     */
    protected function updateGuidInB24(int $b24Id, string $guid): void
    {
        try {
            $propertyIds = $this->getProductPropertyIds();

            if (!isset($propertyIds['GUID_1C'])) {
                Log::warning('GUID_1C property not found for products');
                return;
            }

            $fields = [
                'PROPERTY_' . $propertyIds['GUID_1C'] => $guid,
            ];

            $this->b24Service->call('crm.product.update', [
                'id' => $b24Id,
                'fields' => $fields,
            ]);

            Log::debug('GUID updated in B24 product', [
                'b24_id' => $b24Id,
                'guid' => $guid,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update GUID in B24 product', [
                'b24_id' => $b24Id,
                'guid' => $guid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Получить ID свойств товара
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
     * Проверка удаления товара
     */
    protected function isDeleted(array $b24Item): bool
    {
        // В B24 товары имеют поле ACTIVE
        return ($b24Item['ACTIVE'] ?? 'Y') === 'N';
    }

    protected function mapToLocal(array $b24Item): array
    {
        $mapper = new B24ProductMapper($this->b24Service);
        return $mapper->map($b24Item);
    }

    protected function findOrCreateLocal(int $b24Id)
    {
        return Product::firstOrNew(['b24_id' => $b24Id]);
    }

    /**
     * Получить класс модели для поиска
     * Должен быть переопределён в наследниках
     */
    protected function getModelClass(): string
    {
        return Product::class;
    }
    public function syncOneById(int $b24ProductId): ?string
    {
        // 1) Быстрый путь: уже есть локально
        $existing = Product::where('b24_id', $b24ProductId)->first();
        if ($existing?->guid_1c) {
            return $existing->guid_1c;
        }

        // 2) Готовим select
        $propertyIds = $this->getProductPropertyIds();

        $select = $this->getSelectFields();
        if (isset($propertyIds['GUID_1C'])) {
            $select[] = 'PROPERTY_' . $propertyIds['GUID_1C'];
        }
        if (isset($propertyIds['LAST_UPDATE_FROM_1C'])) {
            $select[] = 'PROPERTY_' . $propertyIds['LAST_UPDATE_FROM_1C'];
        }
        if (isset($propertyIds['ANALYTICS_GROUP'])) {
            $select[] = 'PROPERTY_' . $propertyIds['ANALYTICS_GROUP'];
        }
        if (isset($propertyIds['ANALYTICS_GROUP_GUID'])) {
            $select[] = 'PROPERTY_' . $propertyIds['ANALYTICS_GROUP_GUID'];
        }

        // 3) Тянем товар из B24
        $response = $this->b24Service->call('crm.product.list', [
            'filter' => ['=ID' => $b24ProductId],
            'select' => $select,
        ]);

        $item = $response['result'][0] ?? null;
        if (!$item) {
            Log::warning('B24 product not found by ID', ['b24_product_id' => $b24ProductId]);
            return null;
        }

        if ($this->isDeleted($item)) {
            Log::info('B24 product is inactive', ['b24_product_id' => $b24ProductId]);
            return null;
        }

        // 4) Маппим
        $data = $this->mapToLocal($item);

        // ======================================================
        // 5) 🔑 ИЗВЛЕКАЕМ guid_1c — маппер его НЕ добавляет!
        //    Используем тот же extractGuid1C(), что и в основном потоке
        // ======================================================
        $guid1c = $this->extractGuid1C($item);
        if (!empty($guid1c)) {
            $data['guid_1c'] = $guid1c;
        }

        // 6) Сохраняем
        $product = Product::updateOrCreate(
            ['b24_id' => $b24ProductId],
            $data
        );

        if (empty($product->guid_1c)) {
            Log::warning('B24 product synced but guid_1c is empty', [
                'b24_product_id' => $b24ProductId,
                'product_name' => $product->name ?? ($item['NAME'] ?? null),
            ]);
        } else {
            Log::debug('B24 product synced with guid_1c', [
                'b24_product_id' => $b24ProductId,
                'guid_1c' => $product->guid_1c,
            ]);
        }

        return $product->guid_1c;
    }

}
