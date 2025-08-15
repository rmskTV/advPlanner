<?php

namespace Modules\EnterpriseData\app\Services;

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

            // Определяем тип контактной информации и извлекаем данные
            if (str_contains($type, 'Телефон') || str_contains($type, 'Phone')) {
                $contactInfo['phone'] = self::extractPhone($xmlValues);
            } elseif (str_contains($type, 'Email') || str_contains($type, 'Почта')) {
                $contactInfo['email'] = self::extractEmail($xmlValues);
            } elseif (str_contains($type, 'Адрес') || str_contains($type, 'Address')) {
                $addressData = self::extractAddress($xmlValues);
                if ($type === 'ЮридическийАдрес') {
                    $contactInfo['address'] = $addressData['address'];
                    $contactInfo['zip'] = $addressData['zip'];
                }
            }
        }

        return $contactInfo;
    }

    /**
     * Извлечение номера телефона из экранированного XML 1С
     */
    private static function extractPhone(string $escapedXml): ?string
    {
        if (empty($escapedXml)) {
            return null;
        }

        // Раскодируем HTML entities
        $xmlString = html_entity_decode($escapedXml);

        // Способ 1: Извлекаем атрибут Представление (самый надежный)
        if (preg_match('/Представление="([^"]*)"/', $xmlString, $matches)) {
            $representation = $matches[1];

            return self::cleanPhone($representation);
        }

        // Способ 2: Парсим структурированные данные телефона
        $phoneData = self::parsePhoneStructure($xmlString);
        if ($phoneData) {
            return $phoneData;
        }

        // Способ 3: Если это простая строка
        if (! str_contains($xmlString, '<')) {
            return self::cleanPhone($xmlString);
        }

        return null;
    }

    /**
     * Парсинг структурированных данных телефона из XML
     */
    private static function parsePhoneStructure(string $xmlString): ?string
    {
        // Извлекаем компоненты номера телефона
        $countryCode = '';
        $cityCode = '';
        $number = '';
        $extension = '';

        if (preg_match('/КодСтраны="([^"]*)"/', $xmlString, $matches)) {
            $countryCode = $matches[1];
        }

        if (preg_match('/КодГорода="([^"]*)"/', $xmlString, $matches)) {
            $cityCode = $matches[1];
        }

        if (preg_match('/Номер="([^"]*)"/', $xmlString, $matches)) {
            $number = $matches[1];
        }

        if (preg_match('/Добавочный="([^"]*)"/', $xmlString, $matches)) {
            $extension = $matches[1];
        }

        // Собираем номер телефона
        $phoneComponents = [];

        if (! empty($countryCode)) {
            $phoneComponents[] = "({$countryCode})";
        }

        if (! empty($cityCode)) {
            $phoneComponents[] = $cityCode;
        }

        if (! empty($number)) {
            $phoneComponents[] = $number;
        }

        if (! empty($extension)) {
            $phoneComponents[] = "доб. {$extension}";
        }

        $result = implode(' ', $phoneComponents);

        return ! empty($result) ? $result : null;
    }

    /**
     * Извлечение email из экранированного XML 1С
     */
    private static function extractEmail(string $escapedXml): ?string
    {
        if (empty($escapedXml)) {
            return null;
        }

        // Раскодируем HTML entities
        $xmlString = html_entity_decode($escapedXml);

        // Извлекаем из атрибута Представление
        if (preg_match('/Представление="([^"]*)"/', $xmlString, $matches)) {
            $email = $matches[1];

            return self::cleanEmail($email);
        }

        // Извлекаем из атрибута Значение
        if (preg_match('/Значение="([^"]*)"/', $xmlString, $matches)) {
            $email = $matches[1];

            return self::cleanEmail($email);
        }

        return null;
    }

    /**
     * Извлечение адреса из экранированного XML 1С
     */
    private static function extractAddress(string $escapedXml): array
    {
        $result = [
            'address' => null,
            'zip' => null,
        ];

        if (empty($escapedXml)) {
            return $result;
        }

        // Раскодируем HTML entities
        $xmlString = html_entity_decode($escapedXml);

        // Извлекаем представление адреса
        if (preg_match('/Представление="([^"]*)"/', $xmlString, $matches)) {
            $representation = $matches[1];

            // Извлекаем индекс из начала адреса
            if (preg_match('/^(\d{6}),?\s*(.+)/', $representation, $addressMatches)) {
                $result['zip'] = $addressMatches[1];
                $result['address'] = trim($addressMatches[2]);
            } else {
                $result['address'] = $representation;
            }
        }

        return $result;
    }

    /**
     * Очистка номера телефона
     */
    private static function cleanPhone(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        // Убираем лишние пробелы
        $phone = preg_replace('/\s+/', ' ', trim($phone));

        // Ограничиваем длину
        $result = strlen($phone) > 50 ? substr($phone, 0, 50) : $phone;

        return ! empty($result) ? $result : null;
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

        // Проверяем базовый формат email
        if (! filter_var($cleaned, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        // Ограничиваем длину
        return strlen($cleaned) > 100 ? substr($cleaned, 0, 100) : $cleaned;
    }
}
