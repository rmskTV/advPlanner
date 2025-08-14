<?php

namespace Modules\EnterpriseData\app\Services;

use Illuminate\Support\Facades\Log;

class ContactInfoParser
{
    /**
     * Парсинг контактной информации 1С
     */
    public static function parseContactInfo(array $tabularSections): array
    {
        $contactInfo = [
            'phone' => null,
            'email' => null,
            'address' => null,
            'zip' => null,
        ];

        $contactSection = $tabularSections['КонтактнаяИнформация'] ?? [];

        foreach ($contactSection as $row) {
            $type = $row['ВидКонтактнойИнформации'] ?? '';
            $xmlValues = $row['ЗначенияПолей'] ?? '';

            Log::debug('Processing contact info', [
                'type' => $type,
                'xml_preview' => substr($xmlValues, 0, 100),
            ]);

            // Извлекаем представление из XML
            $representation = self::extractRepresentation($xmlValues);

            if (empty($representation)) {
                continue;
            }

            // Определяем тип контактной информации
            if (str_contains($type, 'Телефон') || str_contains($type, 'Phone')) {
                $contactInfo['phone'] = self::cleanPhone($representation);
            } elseif (str_contains($type, 'Email') || str_contains($type, 'Почта')) {
                $contactInfo['email'] = self::cleanEmail($representation);
            } elseif (str_contains($type, 'Адрес') || str_contains($type, 'Address')) {
                $addressData = self::parseAddress($representation, $xmlValues);
                $contactInfo['address'] = $addressData['address'];
                $contactInfo['zip'] = $addressData['zip'];
            }
        }

        return $contactInfo;
    }

    /**
     * Извлечение представления из XML контактной информации
     */
    private static function extractRepresentation(string $xmlString): ?string
    {
        if (empty($xmlString)) {
            return null;
        }

        // Пытаемся извлечь атрибут Представление
        if (preg_match('/Представление="([^"]*)"/', $xmlString, $matches)) {
            return html_entity_decode($matches[1]);
        }

        // Если это простая строка без XML
        if (! str_contains($xmlString, '<')) {
            return $xmlString;
        }

        return null;
    }

    /**
     * Очистка номера телефона
     */
    private static function cleanPhone(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        // Убираем лишние символы, оставляем только цифры, +, -, (, ), пробелы
        $cleaned = preg_replace('/[^\d\+\-$$$$\s]/', '', $phone);
        $cleaned = trim($cleaned);

        // Ограничиваем длину
        return strlen($cleaned) > 50 ? substr($cleaned, 0, 50) : ($cleaned ?: null);
    }

    /**
     * Очистка email
     */
    private static function cleanEmail(?string $email): ?string
    {
        if (empty($email)) {
            return null;
        }

        $cleaned = trim($email);

        // Ограничиваем длину
        return strlen($cleaned) > 100 ? substr($cleaned, 0, 100) : ($cleaned ?: null);
    }

    /**
     * Парсинг адреса
     */
    private static function parseAddress(string $representation, string $xmlString): array
    {
        $result = [
            'address' => null,
            'zip' => null,
        ];

        // Извлекаем индекс из представления (обычно в начале)
        if (preg_match('/^(\d{6}),?\s*(.+)/', $representation, $matches)) {
            $result['zip'] = $matches[1];
            $result['address'] = trim($matches[2]);
        } else {
            $result['address'] = $representation;
        }

        // Пытаемся извлечь индекс из XML
        if (empty($result['zip']) && preg_match('/Значение="(\d{6})"/', $xmlString, $matches)) {
            $result['zip'] = $matches[1];
        }

        return $result;
    }
}
