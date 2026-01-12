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
}
