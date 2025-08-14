<?php

namespace Modules\EnterpriseData\app\Mappings;

use App\Models\UnitOfMeasure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

class UnitOfMeasureMapping extends ObjectMapping
{
    public function getObjectType(): string
    {
        return 'Справочник.ЕдиницыИзмерения';
    }

    public function getModelClass(): string
    {
        return UnitOfMeasure::class;
    }

    public function mapFrom1C(array $object1C): Model
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];

        Log::info('Mapping UnitOfMeasure from 1C', [
            'object_type' => $object1C['type'],
            'ref' => $object1C['ref'] ?? 'not set'
        ]);

        $unit = new UnitOfMeasure();

        // Основные реквизиты из ключевых свойств
        $unit->guid_1c = $this->getFieldValue($keyProperties, 'Ссылка') ?: ($object1C['ref'] ?? null);

        // Данные классификатора
        $classifierData = $keyProperties['ДанныеКлассификатора'] ?? [];
        if (!empty($classifierData)) {
            $unit->code = $classifierData['Код'] ?? null;
            $unit->name = $classifierData['Наименование'] ?? null;
        }

        // Полное наименование
        $unit->full_name = $this->getFieldValue($properties, 'НаименованиеПолное');

        // Системные поля
        $unit->deletion_mark = false;
        $unit->last_sync_at = now();

        Log::info('Mapped UnitOfMeasure successfully', [
            'guid_1c' => $unit->guid_1c,
            'code' => $unit->code,
            'name' => $unit->name,
            'full_name' => $unit->full_name
        ]);

        return $unit;
    }

    public function mapTo1C(Model $laravelModel): array
    {
        /** @var UnitOfMeasure $laravelModel */
        return [
            'type' => 'Справочник.ЕдиницыИзмерения',
            'ref' => $laravelModel->guid_1c,
            'properties' => [
                'КлючевыеСвойства' => [
                    'Ссылка' => $laravelModel->guid_1c,
                    'ДанныеКлассификатора' => [
                        'Код' => $laravelModel->code,
                        'Наименование' => $laravelModel->name,
                    ],
                ],
                'НаименованиеПолное' => $laravelModel->full_name,
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

        $classifierData = $keyProperties['ДанныеКлассификатора'] ?? [];
        if (empty($classifierData)) {
            $warnings[] = 'Unit classifier data is missing';
        }

        return ValidationResult::success($warnings);
    }
}
