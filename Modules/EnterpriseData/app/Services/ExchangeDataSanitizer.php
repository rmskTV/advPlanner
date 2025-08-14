<?php

namespace Modules\EnterpriseData\app\Services;

use Modules\EnterpriseData\app\Exceptions\ExchangeMappingException;

class ExchangeDataSanitizer
{
    private const MAX_STRING_LENGTH = 1000;

    private const MAX_ARRAY_DEPTH = 10;

    private const DANGEROUS_PATTERNS = [
        '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi',
        '/javascript:/i',
        '/vbscript:/i',
        '/on\w+\s*=/i',
        '/<iframe\b/i',
        '/<object\b/i',
        '/<embed\b/i',
        '/<form\b/i',
    ];

    public function sanitizeIncomingObject(array $object1C): array
    {
        try {
            // Проверка глубины вложенности
            $this->validateArrayDepth($object1C, 0);

            return $this->sanitizeArray($object1C, 'incoming');

        } catch (\Exception $e) {
            throw new ExchangeMappingException('Failed to sanitize incoming object: '.$e->getMessage(), 0, $e);
        }
    }

    public function sanitizeOutgoingObject(array $object1C): array
    {
        try {
            return $this->sanitizeArray($object1C, 'outgoing');

        } catch (\Exception $e) {
            throw new ExchangeMappingException('Failed to sanitize outgoing object: '.$e->getMessage(), 0, $e);
        }
    }

    private function sanitizeArray(array $data, string $direction, int $depth = 0): array
    {
        if ($depth > self::MAX_ARRAY_DEPTH) {
            throw new ExchangeMappingException('Array depth limit exceeded');
        }

        $sanitized = [];

        foreach ($data as $key => $value) {
            // Санитизация ключа
            $sanitizedKey = $this->sanitizeKey($key);

            if (is_array($value)) {
                $sanitized[$sanitizedKey] = $this->sanitizeArray($value, $direction, $depth + 1);
            } elseif (is_string($value)) {
                $sanitized[$sanitizedKey] = $this->sanitizeString($value, $direction);
            } else {
                $sanitized[$sanitizedKey] = $this->sanitizeScalar($value);
            }
        }

        return $sanitized;
    }

    private function sanitizeString(string $value, string $direction): string
    {
        // Ограничение длины строки
        if (strlen($value) > self::MAX_STRING_LENGTH) {
            $value = substr($value, 0, self::MAX_STRING_LENGTH);
        }

        // Удаление управляющих символов (кроме переносов строк и табуляции)
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        // Проверка на опасные паттерны для входящих данных
        if ($direction === 'incoming') {
            foreach (self::DANGEROUS_PATTERNS as $pattern) {
                if (preg_match($pattern, $value)) {
                    throw new ExchangeMappingException('Dangerous content detected in string value');
                }
            }
        }

        // Кодирование HTML-сущностей для безопасности
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');

        // Дополнительная очистка для XML
        $value = $this->sanitizeForXml($value);

        return $value;
    }

    private function sanitizeKey(string $key): string
    {
        // Ключи должны быть безопасными для использования в XML
        $key = preg_replace('/[^a-zA-Z0-9_\-\.А-Яа-я]/u', '_', $key);

        // Ограничение длины ключа
        if (strlen($key) > 100) {
            $key = substr($key, 0, 100);
        }

        return $key;
    }

    private function sanitizeScalar(mixed $value): mixed
    {
        if (is_bool($value) || is_null($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            // Проверка на разумные пределы для чисел
            if (is_int($value)) {
                return max(-2147483648, min(2147483647, $value));
            } elseif (is_float($value)) {
                return max(-1e308, min(1e308, $value));
            }
        }

        // Преобразование в строку и санитизация
        return $this->sanitizeString((string) $value, 'incoming');
    }

    private function sanitizeForXml(string $value): string
    {
        // Удаление недопустимых XML символов
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

        // Замена амперсандов, которые не являются частью сущностей
        $value = preg_replace('/&(?!(?:amp|lt|gt|quot|apos);)/', '&amp;', $value);

        return $value;
    }

    private function validateArrayDepth(array $data, int $currentDepth): void
    {
        if ($currentDepth > self::MAX_ARRAY_DEPTH) {
            throw new ExchangeMappingException('Array depth limit exceeded');
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $this->validateArrayDepth($value, $currentDepth + 1);
            }
        }
    }
}
