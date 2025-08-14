<?php

namespace Modules\EnterpriseData\app\Mappings;

use App\Models\CounterpartyGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

class CounterpartyGroupMapping extends ObjectMapping
{
    public function getObjectType(): string
    {
        return 'Справочник.КонтрагентыГруппа';
    }

    public function getModelClass(): string
    {
        return CounterpartyGroup::class;
    }

    public function mapFrom1C(array $object1C): Model
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];

        Log::info('Mapping CounterpartyGroup from 1C', [
            'object_type' => $object1C['type'],
            'ref' => $object1C['ref'] ?? 'not set',
            'key_properties_keys' => array_keys($keyProperties)
        ]);

        $group = new CounterpartyGroup();

        // Основные реквизиты из ключевых свойств
        $group->guid_1c = $this->getFieldValue($keyProperties, 'Ссылка') ?: ($object1C['ref'] ?? null);
        $group->name = $this->getFieldValue($keyProperties, 'Наименование') ?: 'Группа без названия';

        // Родительская группа (если есть)
        $parentData = $keyProperties['Родитель'] ?? [];
        if (!empty($parentData) && isset($parentData['Ссылка'])) {
            $parentGroup = CounterpartyGroup::findByGuid1C($parentData['Ссылка']);
            $group->parent_id = $parentGroup?->id;
            $group->parent_guid_1c = $parentData['Ссылка'];
        }

        // Системные поля
        $group->deletion_mark = false;
        $group->last_sync_at = now();

        Log::info('Mapped CounterpartyGroup successfully', [
            'guid_1c' => $group->guid_1c,
            'name' => $group->name,
            'parent_guid' => $group->parent_guid_1c
        ]);

        return $group;
    }

    public function mapTo1C(Model $laravelModel): array
    {
        /** @var CounterpartyGroup $laravelModel */
        return [
            'type' => 'Справочник.КонтрагентыГруппа',
            'ref' => $laravelModel->guid_1c,
            'properties' => [
                'КлючевыеСвойства' => [
                    'Ссылка' => $laravelModel->guid_1c,
                    'Наименование' => $laravelModel->name,
                ],
            ],
            'tabular_sections' => []
        ];
    }

    public function validateStructure(array $object1C): ValidationResult
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];
        $warnings = [];

        // Проверяем наличие ключевых свойств
        if (empty($keyProperties)) {
            return ValidationResult::failure(['КлючевыеСвойства section is missing']);
        }

        // Проверяем название
        $name = $this->getFieldValue($keyProperties, 'Наименование');
        if (empty(trim($name))) {
            $warnings[] = 'Group name is missing';
        }

        return ValidationResult::success($warnings);
    }
}
