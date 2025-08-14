<?php

namespace Modules\EnterpriseData\app\Mappings;

use App\Models\Contract;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

class ContractMapping extends ObjectMapping
{
    public function getObjectType(): string
    {
        return 'Справочник.Договоры';
    }

    public function getModelClass(): string
    {
        return Contract::class;
    }

    public function mapTo1C(Model $laravelModel): array
    {
        /** @var Contract $laravelModel */
        return [
            'type' => 'Справочник.Договоры',
            'ref' => $laravelModel->guid_1c,
            'properties' => [
                'КлючевыеСвойства' => [
                    'Ссылка' => $laravelModel->guid_1c,
                    'Номер' => $laravelModel->number,
                    'Дата' => $laravelModel->date->format('Y-m-d'),
                    'Наименование' => $laravelModel->name,
                    'ВидДоговора' => $laravelModel->contract_type,
                    'РасчетыВУсловныхЕдиницах' => $laravelModel->calculations_in_conditional_units ? 'true' : 'false',
                ],
                'УчетАгентскогоНДС' => $laravelModel->is_agent_contract ? 'true' : 'false',
                'ВидАгентскогоДоговора' => $laravelModel->agent_contract_type,
            ],
            'tabular_sections' => [],
        ];
    }

    public function mapFrom1C(array $object1C): Model
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];

        Log::info('Mapping Contract from 1C', [
            'object_type' => $object1C['type'],
            'ref' => $object1C['ref'] ?? 'not set',
            'has_key_properties' => ! empty($keyProperties),
        ]);

        $contract = new Contract;

        // Основные реквизиты из ключевых свойств - ТОЧНО как в 1С
        $contract->guid_1c = $this->getFieldValue($keyProperties, 'Ссылка') ?: ($object1C['ref'] ?? null);

        // Номер договора - как есть или null
        $number = $this->getFieldValue($keyProperties, 'Номер');
        $contract->number = ! empty(trim($number)) ? trim($number) : null;

        // Дата договора - как есть или null
        $dateString = $this->getFieldValue($keyProperties, 'Дата');
        if (! empty($dateString)) {
            try {
                $contract->date = Carbon::parse($dateString);
            } catch (\Exception $e) {
                Log::warning('Invalid date format, setting to null', [
                    'original_date' => $dateString,
                    'error' => $e->getMessage(),
                ]);
                $contract->date = null;
            }
        } else {
            $contract->date = null;
        }

        // Название - как есть или null
        $name = $this->getFieldValue($keyProperties, 'Наименование');
        $contract->name = ! empty(trim($name)) ? trim($name) : null;

        // Организация
        $organizationData = $keyProperties['Организация'] ?? [];
        if (! empty($organizationData) && isset($organizationData['Ссылка'])) {
            $organization = Organization::findByGuid1C($organizationData['Ссылка']);
            $contract->organization_id = $organization?->id;
        }

        // Контрагент
        $counterpartyData = $keyProperties['Контрагент'] ?? [];
        if (! empty($counterpartyData)) {
            $contract->counterparty_guid_1c = $counterpartyData['Ссылка'] ?? null;
        }

        // Валюта
        $currencyData = $keyProperties['ВалютаВзаиморасчетов'] ?? [];
        if (! empty($currencyData)) {
            $contract->currency_guid_1c = $currencyData['Ссылка'] ?? null;
        }

        // Вид договора
        $contract->contract_type = $this->getFieldValue($keyProperties, 'ВидДоговора');

        // Дополнительные свойства
        $contract->description = $this->getFieldValue($properties, 'Комментарий') ?:
            $this->getFieldValue($properties, 'Описание');

        // Агентские договоры
        $contract->is_agent_contract = $this->getBooleanFieldValue($properties, 'УчетАгентскогоНДС', false);
        $contract->agent_contract_type = $this->getFieldValue($properties, 'ВидАгентскогоДоговора');

        // Расчеты в условных единицах
        $contract->calculations_in_conditional_units = $this->getBooleanFieldValue($keyProperties, 'РасчетыВУсловныхЕдиницах', false);

        // Системные поля
        $contract->deletion_mark = false;
        $contract->is_active = true;
        $contract->last_sync_at = now();

        Log::info('Mapped Contract successfully', [
            'guid_1c' => $contract->guid_1c,
            'number' => $contract->number,
            'date' => $contract->date?->format('Y-m-d'),
            'name' => $contract->name,
            'contract_type' => $contract->contract_type,
            'has_missing_fields' => empty($contract->number) || empty($contract->date) || empty($contract->name),
        ]);

        return $contract;
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

        // Проверяем основные поля и выдаем только предупреждения
        $number = $this->getFieldValue($keyProperties, 'Номер');
        if (empty(trim($number))) {
            $warnings[] = 'Contract number is missing';
        }

        $date = $this->getFieldValue($keyProperties, 'Дата');
        if (empty($date)) {
            $warnings[] = 'Contract date is missing';
        } else {
            try {
                Carbon::parse($date);
            } catch (\Exception $e) {
                $warnings[] = 'Invalid contract date format: '.$date;
            }
        }

        $name = $this->getFieldValue($keyProperties, 'Наименование');
        if (empty(trim($name))) {
            $warnings[] = 'Contract name is missing';
        }

        // Всегда возвращаем успех, но с предупреждениями
        return ValidationResult::success($warnings);
    }
}
