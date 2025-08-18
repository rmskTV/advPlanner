<?php

namespace Modules\EnterpriseData\app\Contracts;

use Illuminate\Database\Eloquent\Model;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

abstract class ObjectMapping
{
    abstract public function getObjectType(): string;

    abstract public function getModelClass(): string;

    abstract public function mapFrom1C(array $object1C): Model;

    abstract public function mapTo1C(Model $laravelModel): array;

    abstract public function validateStructure(array $object1C): ValidationResult;

    /**
     * Получение значения поля с поддержкой разных вариантов названий
     */
    protected function getFieldValue(array $properties, string $fieldName, $default = null)
    {
        // Прямое совпадение
        if (isset($properties[$fieldName])) {
            return $properties[$fieldName];
        }

        // Поиск без учета регистра
        foreach ($properties as $key => $value) {
            if (strcasecmp($key, $fieldName) === 0) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Получение булевого значения поля
     */
    protected function getBooleanFieldValue(array $properties, string $fieldName, bool $default = false): bool
    {
        $value = $this->getFieldValue($properties, $fieldName, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'да', 'yes']);
        }

        return (bool) $value;
    }

    /**
     * Валидация обязательных полей
     */
    protected function validateRequiredFields(array $object1C, array $requiredFields): ValidationResult
    {
        $errors = [];

        foreach ($requiredFields as $field) {
            if (str_contains($field, '.')) {
                // Вложенное поле (например, properties.Наименование)
                $parts = explode('.', $field);
                $current = $object1C;

                foreach ($parts as $part) {
                    if (! isset($current[$part])) {
                        $errors[] = "Required field missing: {$field}";
                        break;
                    }
                    $current = $current[$part];
                }

                // Проверяем что значение не пустое
                if (isset($current) && empty(trim($current))) {
                    $errors[] = "Required field is empty: {$field}";
                }
            } else {
                // Простое поле
                if (! isset($object1C[$field]) || empty(trim($object1C[$field]))) {
                    $errors[] = "Required field missing or empty: {$field}";
                }
            }
        }

        return empty($errors)
            ? ValidationResult::success()
            : ValidationResult::failure($errors);
    }

    protected function getStringValue(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (is_array($value)) {
            // Если это массив, сериализуем в JSON
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return $value ? (string) $value : null;
    }
}
