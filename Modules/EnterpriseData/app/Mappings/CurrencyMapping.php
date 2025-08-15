<?php

namespace Modules\EnterpriseData\app\Mappings;

use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\app\Models\Currency;
use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

class CurrencyMapping extends ObjectMapping
{
    public function getObjectType(): string
    {
        return 'Справочник.Валюты';
    }

    public function getModelClass(): string
    {
        return Currency::class;
    }

    public function mapFrom1C(array $object1C): Model
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];

        $currency = new Currency;

        // Основные реквизиты из ключевых свойств
        $currency->guid_1c = $this->getFieldValue($keyProperties, 'Ссылка') ?: ($object1C['ref'] ?? null);

        // Данные классификатора
        $classifierData = $keyProperties['ДанныеКлассификатора'] ?? [];
        if (! empty($classifierData)) {
            $currency->code = $classifierData['Код'] ?? null;
            $currency->name = $classifierData['Наименование'] ?? null;
        }

        // Полное наименование
        $currency->full_name = $this->getFieldValue($properties, 'НаименованиеПолное');

        // Параметры прописи
        $currency->spelling_parameters = $this->getFieldValue($properties, 'ПараметрыПрописи');

        // Определяем основную валюту (обычно рубль с кодом 643)
        $currency->is_main_currency = ($currency->code === '643');

        // Системные поля
        $currency->deletion_mark = false;
        $currency->last_sync_at = now();

        return $currency;
    }

    public function mapTo1C(Model $laravelModel): array
    {
        /** @var Currency $laravelModel */
        return [
            'type' => 'Справочник.Валюты',
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
                'ПараметрыПрописи' => $laravelModel->spelling_parameters,
            ],
            'tabular_sections' => [],
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

        // Проверяем данные классификатора
        $classifierData = $keyProperties['ДанныеКлассификатора'] ?? [];
        if (empty($classifierData)) {
            $warnings[] = 'Currency classifier data is missing';
        } else {
            $code = $classifierData['Код'] ?? '';
            $name = $classifierData['Наименование'] ?? '';

            if (empty($code)) {
                $warnings[] = 'Currency code is missing';
            }

            if (empty($name)) {
                $warnings[] = 'Currency name is missing';
            }
        }

        return ValidationResult::success($warnings);
    }
}
