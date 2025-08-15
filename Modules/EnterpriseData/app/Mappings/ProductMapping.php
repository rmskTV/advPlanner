<?php

namespace Modules\EnterpriseData\app\Mappings;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\Product;
use Modules\Accounting\app\Models\ProductGroup;
use Modules\Accounting\app\Models\UnitOfMeasure;
use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

class ProductMapping extends ObjectMapping
{
    public function getObjectType(): string
    {
        return 'Справочник.Номенклатура';
    }

    public function getModelClass(): string
    {
        return Product::class;
    }

    public function mapFrom1C(array $object1C): Model
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];

        Log::info('Mapping Product from 1C', [
            'object_type' => $object1C['type'],
            'ref' => $object1C['ref'] ?? 'not set'
        ]);

        $product = new Product();

        // Основные реквизиты из ключевых свойств
        $product->guid_1c = $this->getFieldValue($keyProperties, 'Ссылка') ?: ($object1C['ref'] ?? null);
        $product->name = $this->getFieldValue($keyProperties, 'Наименование');
        $product->full_name = $this->getFieldValue($keyProperties, 'НаименованиеПолное');
        $product->code = $this->getFieldValue($keyProperties, 'КодВПрограмме');

        // Группа номенклатуры
        $groupData = $keyProperties['Группа'] ?? [];
        if (!empty($groupData) && isset($groupData['Ссылка'])) {
            $group = ProductGroup::findByGuid1C($groupData['Ссылка']);
            $product->group_id = $group?->id;
            $product->group_guid_1c = $groupData['Ссылка'];
        }

        // Тип номенклатуры
        $type1C = $this->getFieldValue($properties, 'ТипНоменклатуры');
        $product->product_type = match($type1C) {
            'Товар' => Product::TYPE_PRODUCT,
            'Услуга' => Product::TYPE_SERVICE,
            'Набор' => Product::TYPE_SET,
            default => Product::TYPE_PRODUCT
        };

        // Единица измерения
        $unitData = $properties['ЕдиницаИзмерения'] ?? [];
        if (!empty($unitData) && isset($unitData['Ссылка'])) {
            $unit = UnitOfMeasure::findByGuid1C($unitData['Ссылка']);
            $product->unit_of_measure_id = $unit?->id;
            $product->unit_guid_1c = $unitData['Ссылка'];
        }

        // НДС
        $product->vat_rate = $this->getFieldValue($properties, 'СтавкаНДС');

        // Код ТРУ
        $product->tru_code = $this->getFieldValue($properties, 'КодТРУ');

        // Группа аналитического учета
        $analyticsData = $properties['ГруппаАналитическогоУчета'] ?? [];
        if (!empty($analyticsData)) {
            $product->analytics_group_guid_1c = $analyticsData['Ссылка'] ?? null;
            $product->analytics_group_code = $analyticsData['КодВПрограмме'] ?? null;
            $product->analytics_group_name = $analyticsData['Наименование'] ?? null;
        }

        // Вид номенклатуры
        $kindData = $properties['ВидНоменклатуры'] ?? [];
        if (!empty($kindData)) {
            $product->product_kind_guid_1c = $kindData['Ссылка'] ?? null;
            $product->product_kind_name = $kindData['Наименование'] ?? null;
        }

        // Алкогольная продукция
        $alcoholData = $properties['ДанныеАлкогольнойПродукции'] ?? [];
        if (!empty($alcoholData)) {
            $product->is_alcoholic = $this->getBooleanFieldValue($alcoholData, 'АлкогольнаяПродукция', false);
            $product->alcohol_type = $alcoholData['ВидАлкогольнойПродукции'] ?? null;
            $product->is_imported_alcohol = $this->getBooleanFieldValue($alcoholData, 'ИмпортнаяАлкогольнаяПродукция', false);
            $product->alcohol_volume = $alcoholData['ОбъемДАЛ'] ?? null;
            $product->alcohol_producer = $alcoholData['ПроизводительИмпортер'] ?? null;
        }

        // Прослеживаемость
        $product->is_traceable = $this->getBooleanFieldValue($properties, 'ПрослеживаемыйТовар', false);

        // Системные поля
        $product->deletion_mark = false;
        $product->last_sync_at = now();

        Log::info('Mapped Product successfully', [
            'guid_1c' => $product->guid_1c,
            'name' => $product->name,
            'code' => $product->code,
            'product_type' => $product->product_type,
            'group_guid' => $product->group_guid_1c,
            'unit_guid' => $product->unit_guid_1c
        ]);

        return $product;
    }

    public function mapTo1C(Model $laravelModel): array
    {
        /** @var Product $laravelModel */
        $type1C = match($laravelModel->product_type) {
            Product::TYPE_PRODUCT => 'Товар',
            Product::TYPE_SERVICE => 'Услуга',
            Product::TYPE_SET => 'Набор',
            default => 'Товар'
        };

        return [
            'type' => 'Справочник.Номенклатура',
            'ref' => $laravelModel->guid_1c,
            'properties' => [
                'КлючевыеСвойства' => [
                    'Ссылка' => $laravelModel->guid_1c,
                    'Наименование' => $laravelModel->name,
                    'НаименованиеПолное' => $laravelModel->full_name,
                    'КодВПрограмме' => $laravelModel->code,
                ],
                'ТипНоменклатуры' => $type1C,
                'СтавкаНДС' => $laravelModel->vat_rate,
                'КодТРУ' => $laravelModel->tru_code,
                'ПрослеживаемыйТовар' => $laravelModel->is_traceable ? 'true' : 'false',
            ],
            'tabular_sections' => []
        ];
    }

    public function validateStructure(array $object1C): ValidationResult
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];
        $warnings = [];

        if (empty($keyProperties)) {
            return ValidationResult::failure(['КлючевыеСвойства section is missing']);
        }

        $name = $this->getFieldValue($keyProperties, 'Наименование');
        if (empty(trim($name))) {
            $warnings[] = 'Product name is missing';
        }

        return ValidationResult::success($warnings);
    }
}
