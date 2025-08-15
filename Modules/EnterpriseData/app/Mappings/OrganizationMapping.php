<?php

namespace Modules\EnterpriseData\app\Mappings;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\Organization;
use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\Services\ContactInfoParser;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

class OrganizationMapping extends ObjectMapping
{
    public function getObjectType(): string
    {
        return 'Справочник.Организации';
    }

    public function getModelClass(): string
    {
        return Organization::class;
    }

    public function mapFrom1C(array $object1C): Model
    {
        Log::info('Mapping Organization from 1C', [
            'object_type' => $object1C['type'],
            'ref' => $object1C['ref'] ?? 'not set',
        ]);

        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];

        $organization = new Organization;

        // Основные реквизиты из ключевых свойств
        $organization->guid_1c = $this->getFieldValue($keyProperties, 'Ссылка') ?: ($object1C['ref'] ?? null);
        $organization->name = $this->getFieldValue($keyProperties, 'Наименование', '');
        $organization->full_name = $this->getFieldValue($keyProperties, 'НаименованиеПолное') ?:
            $this->getFieldValue($keyProperties, 'НаименованиеСокращенное');

        // Основные реквизиты из свойств
        $organization->prefix = $this->getFieldValue($properties, 'Префикс');

        // Коды и регистрационные данные
        $organization->inn = $this->cleanString($this->getFieldValue($keyProperties, 'ИНН'));
        $organization->kpp = $this->cleanString($this->getFieldValue($keyProperties, 'КПП'));
        $organization->okpo = $this->cleanString($this->getFieldValue($properties, 'ОКПО'));
        $organization->okato = $this->cleanString($this->getFieldValue($properties, 'ОКАТО'));
        $organization->oktmo = $this->cleanString($this->getFieldValue($properties, 'ОКТМО'));
        $organization->ogrn = $this->cleanString($this->getFieldValue($properties, 'ОГРН'));
        $organization->okved = $this->cleanString($this->getFieldValue($properties, 'ОКВЭД'));
        $organization->okopf = $this->cleanString($this->getFieldValue($properties, 'ОКОПФ'));
        $organization->okfs = $this->cleanString($this->getFieldValue($properties, 'ОКФС'));

        // Обработка контактной информации из табличной части
        $contactInfo = $this->parseContactInformation($object1C['tabular_sections'] ?? []);

        // Ограничиваем длину полей
        $organization->phone = $this->truncateString($contactInfo['phone'] ?? null, 100);
        $organization->email = $this->truncateString($contactInfo['email'] ?? null, 100);
        $organization->legal_address = $contactInfo['address'] ?? null;

        // Системные поля
        $organization->deletion_mark = false;
        $organization->last_sync_at = now();

        Log::info('Mapped Organization successfully', [
            'guid_1c' => $organization->guid_1c,
            'name' => $organization->name,
            'inn' => $organization->inn,
            'phone_length' => strlen($organization->phone ?? ''),
            'email_length' => strlen($organization->email ?? ''),
        ]);

        return $organization;
    }

    /**
     * Парсинг контактной информации из табличной части
     */
    private function parseContactInformation(array $tabularSections): array
    {
        return ContactInfoParser::parseContactInfo($tabularSections);
    }

    /**
     * Извлечение представления из XML контактной информации 1С
     */
    private function extractRepresentationFromContactXml(string $xmlString): ?string
    {
        if (empty($xmlString)) {
            return null;
        }

        // Пытаемся извлечь атрибут Представление
        if (preg_match('/Представление="([^"]*)"/', $xmlString, $matches)) {
            return html_entity_decode($matches[1]);
        }

        // Если не найдено представление, пытаемся извлечь номер телефона
        if (preg_match('/Номер="([^"]*)"/', $xmlString, $matches)) {
            return html_entity_decode($matches[1]);
        }

        // Если это простая строка без XML
        if (! str_contains($xmlString, '<')) {
            return $xmlString;
        }

        return null;
    }

    /**
     * Очистка строки от лишних пробелов
     */
    private function cleanString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return trim($value) ?: null;
    }

    /**
     * Обрезка строки до максимальной длины
     */
    private function truncateString(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if (strlen($value) > $maxLength) {
            Log::warning('Truncating string field', [
                'original_length' => strlen($value),
                'max_length' => $maxLength,
                'truncated_value' => substr($value, 0, $maxLength),
            ]);

            return substr($value, 0, $maxLength);
        }

        return $value ?: null;
    }

    public function mapTo1C(Model $laravelModel): array
    {
        /** @var Organization $laravelModel */
        return [
            'type' => 'Справочник.Организации',
            'ref' => $laravelModel->guid_1c,
            'properties' => [
                'КлючевыеСвойства' => [
                    'Ссылка' => $laravelModel->guid_1c,
                    'Наименование' => $laravelModel->name,
                    'НаименованиеСокращенное' => $laravelModel->name,
                    'НаименованиеПолное' => $laravelModel->full_name ?: $laravelModel->name,
                    'ИНН' => $laravelModel->inn,
                    'КПП' => $laravelModel->kpp,
                ],
                'Префикс' => $laravelModel->prefix,
                'ОКПО' => $laravelModel->okpo,
                'ОКАТО' => $laravelModel->okato,
                'ОКТМО' => $laravelModel->oktmo,
                'ОГРН' => $laravelModel->ogrn,
                'ОКВЭД' => $laravelModel->okved,
                'ОКОПФ' => $laravelModel->okopf,
                'ОКФС' => $laravelModel->okfs,
            ],
            'tabular_sections' => [],
        ];
    }

    public function validateStructure(array $object1C): ValidationResult
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];
        $errors = [];
        $warnings = [];

        // Проверяем наличие ключевых свойств
        if (empty($keyProperties)) {
            $errors[] = 'КлючевыеСвойства section is missing';
        } else {
            // Проверяем наличие названия в ключевых свойствах
            $name = $this->getFieldValue($keyProperties, 'Наименование');
            if (empty(trim($name))) {
                $errors[] = 'Organization name is required in КлючевыеСвойства.Наименование';
            } elseif (strlen($name) > 255) {
                $errors[] = 'Organization name is too long (max 255 characters)';
            }

            // Валидация ИНН если есть
            $inn = $this->getFieldValue($keyProperties, 'ИНН');
            if ($inn && ! $this->isValidInn($inn)) {
                $warnings[] = 'Invalid INN format: '.$inn;
            }
        }

        return empty($errors)
            ? ValidationResult::success($warnings)
            : ValidationResult::failure($errors, $warnings);
    }

    /**
     * Валидация ИНН
     */
    private function isValidInn(string $inn): bool
    {
        $inn = preg_replace('/\D/', '', $inn);

        if (strlen($inn) !== 10 && strlen($inn) !== 12) {
            return false;
        }

        return preg_match('/^\d{10}$|^\d{12}$/', $inn) === 1;
    }
}
