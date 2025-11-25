<?php

namespace Modules\EnterpriseData\app\Mappings;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\ContactPerson;
use Modules\Accounting\app\Models\Counterparty;
use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

class ContactPersonMapping extends ObjectMapping
{
    public function getObjectType(): string
    {
        return 'Справочник.КонтактныеЛица';
    }

    public function getModelClass(): string
    {
        return ContactPerson::class;
    }

    public function mapFrom1C(array $object1C): Model
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];

        $contactPerson = new ContactPerson;

        // 1. GUID контактного лица
        $contactPerson->guid_1c = $this->getFieldValue($keyProperties, 'Ссылка')
            ?: ($object1C['ref'] ?? null);

        // 2. ФИО - разбиваем на составляющие
        $fio = $this->getFieldValue($keyProperties, 'ФИО');
        if (!empty($fio)) {
            $nameParts = $this->parseFIO($fio);
            $contactPerson->last_name = $nameParts['last_name'];
            $contactPerson->first_name = $nameParts['first_name'];
            $contactPerson->middle_name = $nameParts['middle_name'];
            $contactPerson->full_name = trim($fio); // Сохраняем полное ФИО
        }

        // 3. Контрагент - ИСПРАВЛЕННЫЙ ПУТЬ
        $subjectData = $keyProperties['Субъект'] ?? [];
        $counterpartyData = $subjectData['Контрагент'] ?? [];

        if (!empty($counterpartyData) && isset($counterpartyData['Ссылка'])) {
            $counterpartyGuid = $counterpartyData['Ссылка'];

            // Сохраняем GUID контрагента
            $contactPerson->counterparty_guid_1c = $counterpartyGuid;

            // Пытаемся найти контрагента по GUID и связать
            $counterparty = Counterparty::findByGuid1C($counterpartyGuid);
            if ($counterparty) {
                $contactPerson->counterparty_id = $counterparty->id;
            } else {
                Log::warning('Counterparty not found for contact person', [
                    'contact_guid' => $contactPerson->guid_1c,
                    'counterparty_guid' => $counterpartyGuid,
                ]);
            }
        }

        // 4. ОписаниеДолжности - находится ВНЕ КлючевыеСвойства
        $contactPerson->position = $this->getFieldValue($properties, 'ОписаниеДолжности');

        // 5. Контактная информация - табличная часть
        $this->processContactInfo($contactPerson, $properties);

        // 6. Дополнительные поля
        $contactPerson->description = $this->getFieldValue($properties, 'Комментарий');

        // 7. Системные поля
        $contactPerson->deletion_mark = $this->getBooleanFieldValue(
            $keyProperties,
            'ПометкаУдаления',
            false
        );
        $contactPerson->is_active = !$contactPerson->deletion_mark;
        $contactPerson->last_sync_at = now();

        return $contactPerson;
    }

    public function mapTo1C(Model $laravelModel): array
    {
        /** @var ContactPerson $laravelModel */

        // Собираем ФИО из частей
        $fio = trim(implode(' ', array_filter([
            $laravelModel->last_name,
            $laravelModel->first_name,
            $laravelModel->middle_name,
        ]))) ?: $laravelModel->full_name;

        return [
            'type' => 'Справочник.КонтактныеЛица',
            'ref' => $laravelModel->guid_1c,
            'properties' => [
                'КлючевыеСвойства' => [
                    'Ссылка' => $laravelModel->guid_1c,
                    'ФИО' => $fio,
                    'Субъект' => [
                        'Контрагент' => [
                            'Ссылка' => $laravelModel->counterparty_guid_1c
                                ?: $laravelModel->counterparty?->guid_1c,
                        ],
                    ],
                    'ПометкаУдаления' => $laravelModel->deletion_mark ? 'true' : 'false',
                ],
                'ОписаниеДолжности' => $laravelModel->position,
                'Комментарий' => $laravelModel->description,
            ],
            'tabular_sections' => [
                // Здесь можно добавить КонтактнаяИнформация если нужно
            ],
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

        // Проверяем ФИО
        $fio = $this->getFieldValue($keyProperties, 'ФИО');
        if (empty(trim($fio))) {
            $warnings[] = 'Contact person FIO (ФИО) is missing';
        }

        // Проверяем контрагента
        $subjectData = $keyProperties['Субъект'] ?? [];
        $counterpartyData = $subjectData['Контрагент'] ?? [];

        if (empty($counterpartyData) || empty($counterpartyData['Ссылка'])) {
            $warnings[] = 'Counterparty reference (Субъект/Контрагент/Ссылка) is missing';
        }

        // Проверяем должность (опционально)
        $position = $this->getFieldValue($properties, 'ОписаниеДолжности');
        if (empty($position)) {
            $warnings[] = 'Position (ОписаниеДолжности) is missing';
        }

        // Всегда возвращаем успех, но с предупреждениями
        return ValidationResult::success($warnings);
    }

    /**
     * Разбор ФИО на составляющие
     */
    private function parseFIO(string $fio): array
    {
        $fio = trim($fio);
        $parts = preg_split('/\s+/u', $fio);

        return [
            'last_name' => $parts[0] ?? null,
            'first_name' => $parts[1] ?? null,
            'middle_name' => $parts[2] ?? null,
        ];
    }

    /**
     * Обработка контактной информации из табличной части
     */
    private function processContactInfo(ContactPerson $contactPerson, array $properties): void
    {
        $contactInfo = $properties['КонтактнаяИнформация'] ?? [];

        if (empty($contactInfo)) {
            return;
        }

        foreach ($contactInfo as $row) {
            $type = $row['ВидКонтактнойИнформации'] ?? '';
            $value = $row['ЗначенияПолей'] ?? '';

            // Парсим XML из ЗначенияПолей
            $parsedValue = $this->parseContactInfoXML($value);

            switch ($type) {
                case 'Телефон':
                    $contactPerson->phone = $parsedValue;
                    break;
                case 'АдресЭлектроннойПочты':
                case 'Email':
                    $contactPerson->email = $parsedValue;
                    break;
                // Можно добавить другие типы
            }
        }
    }

    /**
     * Парсинг XML контактной информации
     */
    private function parseContactInfoXML(string $xmlString): ?string
    {
        if (empty($xmlString)) {
            return null;
        }

        try {
            // Декодируем HTML entities
            $xmlString = html_entity_decode($xmlString);

            // Ищем атрибут Представление
            if (preg_match('/Представление="([^"]+)"/', $xmlString, $matches)) {
                return $matches[1];
            }

            // Если не нашли Представление, возвращаем как есть
            return null;

        } catch (\Exception $e) {
            Log::warning('Failed to parse contact info XML', [
                'xml' => $xmlString,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
