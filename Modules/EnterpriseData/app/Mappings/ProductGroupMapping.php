<?php

namespace Modules\EnterpriseData\app\Mappings;

use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\app\Models\ProductGroup;
use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

class ProductGroupMapping extends ObjectMapping
{
    public function getObjectType(): string
    {
        return 'Справочник.НоменклатураГруппа';
    }

    public function getModelClass(): string
    {
        return ProductGroup::class;
    }

    public function mapFrom1C(array $object1C): Model
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];

        $group = new ProductGroup;

        // Основные реквизиты из ключевых свойств
        $group->guid_1c = $this->getFieldValue($keyProperties, 'Ссылка') ?: ($object1C['ref'] ?? null);
        $group->name = $this->getFieldValue($keyProperties, 'Наименование');
        $group->code = $this->getFieldValue($keyProperties, 'КодВПрограмме');

        // Родительская группа (если есть)
        $parentData = $keyProperties['Родитель'] ?? [];
        if (! empty($parentData) && isset($parentData['Ссылка'])) {
            $parentGroup = ProductGroup::findByGuid1C($parentData['Ссылка']);
            $group->parent_id = $parentGroup?->id;
            $group->parent_guid_1c = $parentData['Ссылка'];
        }

        // Системные поля
        $group->deletion_mark = false;
        $group->last_sync_at = now();

        return $group;
    }

    public function mapTo1C(Model $laravelModel): array
    {
        /** @var ProductGroup $laravelModel */
        return [
            'type' => 'Справочник.НоменклатураГруппа',
            'ref' => $laravelModel->guid_1c,
            'properties' => [
                'КлючевыеСвойства' => [
                    'Ссылка' => $laravelModel->guid_1c,
                    'Наименование' => $laravelModel->name,
                    'КодВПрограмме' => $laravelModel->code,
                ],
            ],
            'tabular_sections' => [],
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
            $warnings[] = 'Product group name is missing';
        }

        return ValidationResult::success($warnings);
    }
}
