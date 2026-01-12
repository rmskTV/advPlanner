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
            'name' => $this->cleanString($b24Product['NAME']),
            'code' => $b24Product['CODE'] ?? null,
            'description' => $this->cleanString($b24Product['DESCRIPTION'] ?? null),
            'product_type' => 'service', // По умолчанию услуга (или определять как-то?)
        ];

        // НДС
        $data['vat_rate'] = $this->mapVatRate($b24Product['VAT_ID'] ?? null);

        // Единица измерения
        if (!empty($b24Product['MEASURE'])) {
            $unitGuid = $this->mapMeasureToGuid($b24Product['MEASURE']);
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
            $groupGuid = $this->findGroupGuidBySectionId($b24Product['SECTION_ID']);
            if ($groupGuid) {
                $data['group_guid_1c'] = $groupGuid;

                // Находим ID группы
                $group = ProductGroup::where('guid_1c', $groupGuid)->first();
                if ($group) {
                    $data['group_id'] = $group->id;
                }
            }
        }

        // Кастомные свойства (аналитическая группа и т.д.)
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
                return $section['CODE'];
            }

            // Или ищем локально по b24_id (если группы тоже синхронизируются)
            // TODO: реализовать при необходимости

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
     * Извлечь кастомные свойства
     */
    protected function extractCustomProperties(array $b24Product): array
    {
        $data = [];

        // Получаем ID свойств
        $propertyIds = $this->getProductPropertyIds();

        // Аналитическая группа
        if (isset($propertyIds['ANALYTICS_GROUP'])) {
            $key = 'PROPERTY_' . $propertyIds['ANALYTICS_GROUP'];
            if (!empty($b24Product[$key])) {
                $data['analytics_group_name'] = $b24Product[$key];
            }
        }

        // GUID аналитической группы
        if (isset($propertyIds['ANALYTICS_GROUP_GUID'])) {
            $key = 'PROPERTY_' . $propertyIds['ANALYTICS_GROUP_GUID'];
            if (!empty($b24Product[$key])) {
                $data['analytics_group_guid_1c'] = $b24Product[$key];
            }
        }

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
     * Очистка строки
     */
    protected function cleanString(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
