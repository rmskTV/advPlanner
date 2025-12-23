<?php

namespace Modules\EnterpriseData\app\Mappings;

use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\app\Models\Counterparty;
use Modules\Accounting\app\Models\CounterpartyGroup;
use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\Services\ContactInfoParser;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

class CounterpartyMapping extends ObjectMapping
{
    public function getObjectType(): string
    {
        return 'Справочник.Контрагенты';
    }

    public function getModelClass(): string
    {
        return Counterparty::class;
    }

    public function mapFrom1C(array $object1C): Model
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];

        $counterparty = new Counterparty;

        // Основные реквизиты из ключевых свойств
        $counterparty->guid_1c = $this->getFieldValue($keyProperties, 'Ссылка') ?: ($object1C['ref'] ?? null);
        $counterparty->name = $this->getFieldValue($keyProperties, 'Наименование');
        $counterparty->full_name = $this->getFieldValue($keyProperties, 'НаименованиеПолное');

        // Тип лица
        $entityType = $this->getFieldValue($keyProperties, 'ЮридическоеФизическоеЛицо');
        $counterparty->entity_type = ($entityType === 'ФизическоеЛицо')
            ? Counterparty::ENTITY_TYPE_INDIVIDUAL
            : Counterparty::ENTITY_TYPE_LEGAL;

        // ИНН
        $counterparty->inn = $this->getFieldValue($keyProperties, 'ИНН');
        $counterparty->kpp = $this->getFieldValue($keyProperties, 'КПП');
        $counterparty->ogrn = $this->getFieldValue($keyProperties, 'РегистрационныйНомер');
        $counterparty->is_pseudoip = $this->getFieldValue($keyProperties, 'Самозанятый') == 'true';

        // Группа контрагента
        $groupData = $keyProperties['Группа'] ?? [];
        if (! empty($groupData) && isset($groupData['Ссылка'])) {
            $group = CounterpartyGroup::findByGuid1C($groupData['Ссылка']);
            $counterparty->group_id = $group?->id;
            $counterparty->group_guid_1c = $groupData['Ссылка'];
        }

        // Страна регистрации
        $countryData = $keyProperties['СтранаРегистрации'] ?? [];
        if (! empty($countryData)) {
            $counterparty->country_guid_1c = $countryData['Ссылка'] ?? null;

            $countryClassifierData = $countryData['ДанныеКлассификатора'] ?? [];
            if (! empty($countryClassifierData)) {
                $counterparty->country_code = $countryClassifierData['Код'] ?? null;
                $counterparty->country_name = $countryClassifierData['Наименование'] ?? null;
            }
        }

        // Регистрационный номер нерезидента
        $counterparty->registration_number = $this->getFieldValue($keyProperties, 'РегистрационныйНомерНерезидента');

        // Обработка контактной информации из табличной части
        $contactInfo = ContactInfoParser::parseContactInfo($object1C['tabular_sections'] ?? []);
        $counterparty->phone = $contactInfo['phone'] ?? null;
        $counterparty->email = $contactInfo['email'] ?? null;
        $counterparty->legal_address = $contactInfo['address'] ?? null;
        $counterparty->legal_address_zip = $contactInfo['zip'] ?? null;

        // Системные поля
        $counterparty->deletion_mark = false;
        $counterparty->last_sync_at = now();

        return $counterparty;
    }

    public function mapTo1C(Model $laravelModel): array
    {
        /** @var Counterparty $laravelModel */
        return [
            'type' => 'Справочник.Контрагенты',
            'ref' => $laravelModel->guid_1c,
            'properties' => [
                'КлючевыеСвойства' => [
                    'Ссылка' => $laravelModel->guid_1c,
                    'Наименование' => $laravelModel->name,
                    'НаименованиеПолное' => $laravelModel->full_name,
                    'ИНН' => $laravelModel->inn,
                    'ЮридическоеФизическоеЛицо' => $laravelModel->isIndividual() ? 'ФизическоеЛицо' : 'ЮридическоеЛицо',
                    'РегистрационныйНомерНерезидента' => $laravelModel->registration_number,
                ],
                'ОбособленноеПодразделение' => $laravelModel->is_separate_division ? 'true' : 'false',
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

        // Проверяем название
        $name = $this->getFieldValue($keyProperties, 'Наименование');
        if (empty(trim($name))) {
            $warnings[] = 'Counterparty name is missing';
        }

        // Проверяем ИНН если есть
        $inn = $this->getFieldValue($keyProperties, 'ИНН');
        if ($inn && ! $this->isValidInn($inn)) {
            $warnings[] = 'Invalid INN format: '.$inn;
        }

        return ValidationResult::success($warnings);
    }

    private function isValidInn(string $inn): bool
    {
        $inn = preg_replace('/\D/', '', $inn);

        return preg_match('/^\d{10}$|^\d{12}$/', $inn) === 1;
    }
}
