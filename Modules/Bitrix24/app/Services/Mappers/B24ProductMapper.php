<?php

namespace Modules\Bitrix24\app\Services\Mappers;

use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\ProductGroup;
use Modules\Accounting\app\Models\UnitOfMeasure;
use Modules\Bitrix24\app\Services\Bitrix24Service;

class B24ProductMapper
{
    protected Bitrix24Service $b24Service;

    public function __construct(Bitrix24Service $b24Service)
    {
        $this->b24Service = $b24Service;
    }

    /**
     * Маппинг товара B24 → Product
     */
    public function map(array $b24Product): array
    {
        $data = [
            'name' => $this->cleanString($b24Product['NAME'] ?? null),
            'code' => $this->cleanString($b24Product['CODE'] ?? null),
            'description' => $this->cleanString($b24Product['DESCRIPTION'] ?? null),
            'product_type' => 'service', // По умолчанию услуга
        ];

        // НДС (в B24 VAT_ID часто строка)
        $vatId = isset($b24Product['VAT_ID']) && $b24Product['VAT_ID'] !== '' ? (int) $b24Product['VAT_ID'] : null;
        $data['vat_rate'] = $this->mapVatRate($vatId);

        // Единица измерения (в B24 поле MEASURE часто строка с кодом)
        if (!empty($b24Product['MEASURE'])) {
            $unitGuid = $this->mapMeasureToGuid((int) $b24Product['MEASURE']);
            if ($unitGuid) {
                $data['unit_guid_1c'] = $unitGuid;

                // Также находим ID единицы измерения
                $unit = UnitOfMeasure::where('guid_1c', $unitGuid)->first();
                if ($unit) {
                    $data['unit_of_measure_id'] = $unit->id;
                }
            }
        }

        // Группа товара
        if (!empty($b24Product['SECTION_ID'])) {
            $groupGuid = $this->findGroupGuidBySectionId((int) $b24Product['SECTION_ID']);
            if ($groupGuid) {
                $data['group_guid_1c'] = $groupGuid;

                // Находим ID группы
                $group = ProductGroup::where('guid_1c', $groupGuid)->first();
                if ($group) {
                    $data['group_id'] = $group->id;
                }
            }
        }

        // Кастомные свойства (аналитическая группа, GUID аналитической группы и т.д.)
        $data = array_merge($data, $this->extractCustomProperties($b24Product));

        return $data;
    }

    /**
     * Маппинг НДС
     */
    protected function mapVatRate(?int $vatId): ?string
    {
        return match ($vatId) {
            1 => 'БезНДС',
            2 => 'НДС0',
            3 => 'НДС10',
            4 => 'НДС18',
            5 => 'НДС20',
            default => null
        };
    }

    /**
     * Маппинг единицы измерения B24 → GUID 1С
     */
    protected function mapMeasureToGuid(int $measureCode): ?string
    {
        // Ищем единицу измерения по коду
        $unit = UnitOfMeasure::where('code', (string) $measureCode)->first();

        return $unit?->guid_1c;
    }

    /**
     * Найти GUID группы по ID секции B24
     */
    protected function findGroupGuidBySectionId(int $sectionId): ?string
    {
        try {
            $response = $this->b24Service->call('crm.productsection.get', [
                'id' => $sectionId,
            ]);

            $section = $response['result'] ?? null;

            if (!$section) {
                return null;
            }

            // CODE секции может содержать GUID
            if (!empty($section['CODE'])) {
                return (string) $section['CODE'];
            }

            // TODO: при необходимости — искать локально по b24_id (если группы синхронизируются)

            return null;

        } catch (\Exception $e) {
            Log::warning('Failed to fetch product section', [
                'section_id' => $sectionId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Извлечь кастомные свойства.
     * Важно: PROPERTY_* в B24 часто приходят объектом вида:
     *   ["valueId" => "...", "value" => "..."]
     * Поэтому мы всегда извлекаем scalar через getB24PropertyScalar().
     */
    protected function extractCustomProperties(array $b24Product): array
    {
        $data = [];

        // Получаем ID свойств
        $propertyIds = $this->getProductPropertyIds();

        // Аналитическая группа (название)
        if (isset($propertyIds['ANALYTICS_GROUP'])) {
            $key = 'PROPERTY_' . $propertyIds['ANALYTICS_GROUP'];
            $val = $this->getB24PropertyScalar($b24Product[$key] ?? null);

            if (!empty($val)) {
                $data['analytics_group_name'] = $this->cleanString($val);
            }
        }

        // GUID аналитической группы
        if (isset($propertyIds['ANALYTICS_GROUP_GUID'])) {
            $key = 'PROPERTY_' . $propertyIds['ANALYTICS_GROUP_GUID'];
            $val = $this->getB24PropertyScalar($b24Product[$key] ?? null);

            if (!empty($val)) {
                $data['analytics_group_guid_1c'] = $this->cleanString($val);
            }
        }

        // (Опционально) Если хотите маппить GUID 1C товара прямо здесь:
        // if (isset($propertyIds['GUID_1C'])) {
        //     $key = 'PROPERTY_' . $propertyIds['GUID_1C'];
        //     $val = $this->getB24PropertyScalar($b24Product[$key] ?? null);
        //     if (!empty($val)) {
        //         $data['guid_1c'] = $this->cleanString($val);
        //     }
        // }

        return $data;
    }

    /**
     * Получить ID свойств товара
     */
    protected function getProductPropertyIds(): array
    {
        return \Cache::remember('b24:product_properties', 3600, function () {
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
     * Нормализует значение B24-свойства к строке.
     *
     * Поддерживает форматы:
     * - null
     * - "строка"
     * - ["value" => "строка", "valueId" => "..."]
     * - [ ... ] (массив значений) — берём первый
     */
    protected function getB24PropertyScalar(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            // Типичный формат: {"valueId": "...", "value": "..."}
            if (array_key_exists('value', $value)) {
                return $this->getB24PropertyScalar($value['value']);
            }

            // Иногда бывает список значений
            if (count($value) === 0) {
                return null;
            }

            return $this->getB24PropertyScalar(reset($value));
        }

        return (string) $value;
    }

    /**
     * Очистка строки (и защита от случайного массива)
     */
    protected function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Если вдруг прилетел массив — извлечём scalar
        if (is_array($value)) {
            $value = $this->getB24PropertyScalar($value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        return trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
