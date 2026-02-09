<?php

namespace Modules\Bitrix24\app\Services\Mappers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\Counterparty;
use Modules\Bitrix24\app\Services\Bitrix24Service;

class B24RequisiteMapper
{
    protected Bitrix24Service $b24Service;

    public function __construct(Bitrix24Service $b24Service)
    {
        $this->b24Service = $b24Service;
    }

    /**
     * Маппинг реквизита B24 → Counterparty
     */
    public function map(array $b24Requisite): array
    {
        Log::info('=== ALL REQUISITE FIELDS ===', [
            'requisite_id' => $b24Requisite['ID'] ?? null,
            'preset_id' => $b24Requisite['PRESET_ID'] ?? null,
            'all_keys' => array_keys($b24Requisite),
            'RQ_fields' => array_filter(
                $b24Requisite,
                fn($key) => str_starts_with($key, 'RQ_'),
                ARRAY_FILTER_USE_KEY
            ),
        ]);
        $presetId = (int) ($b24Requisite['PRESET_ID'] ?? 1);
        $isIp = ($presetId === 3); // 3 = ИП, 1 = Организация

        $data = [
            // Основные поля
            'name' => $this->extractName($b24Requisite, $isIp),
            'full_name' => $this->cleanString($b24Requisite['RQ_COMPANY_FULL_NAME'] ?? $b24Requisite['RQ_COMPANY_NAME'] ?? null),

            // Тип контрагента
            'entity_type' => $isIp ? Counterparty::ENTITY_TYPE_INDIVIDUAL : Counterparty::ENTITY_TYPE_LEGAL,

            // Реквизиты
            'inn' => $this->cleanString($b24Requisite['RQ_INN'] ?? null),
            'kpp' => $this->cleanString($b24Requisite['RQ_KPP'] ?? null),
            'ogrn' => $this->extractOgrn($b24Requisite, $isIp), // 🆕 Улучшенная логика
            'okpo' => $this->cleanString($b24Requisite['RQ_OKPO'] ?? null),

            // 🆕 Страна (хардкод для России)
            'country_code' => '643',
            'country_name' => 'РОССИЯ',
        ];

        // Получаем дополнительные данные из компании-контейнера
        $companyData = $this->fetchCompanyData((int) $b24Requisite['ENTITY_ID']);
        if ($companyData) {
            $data = array_merge($data, $companyData);
        }

        return $data;
    }

    /**
     * Извлечь название контрагента
     */
    protected function extractName(array $b24Requisite, bool $isIp): ?string
    {
        if ($isIp) {
            // Для ИП собираем ФИО
            $lastName = $this->cleanString($b24Requisite['RQ_LAST_NAME'] ?? null);
            $firstName = $this->cleanString($b24Requisite['RQ_FIRST_NAME'] ?? null);
            $secondName = $this->cleanString($b24Requisite['RQ_SECOND_NAME'] ?? null);

            if ($lastName || $firstName) {
                return trim("ИП {$lastName} {$firstName} {$secondName}");
            }
        }

        // Для организации или если ФИО не заполнено
        return $this->cleanString(
            $b24Requisite['RQ_COMPANY_NAME']
            ?? $b24Requisite['NAME']
            ?? null
        );
    }

    /**
     * 🆕 Извлечь ОГРН/ОГРНИП
     *
     * Для ИП: RQ_OGRNIP
     * Для ЮЛ: RQ_OGRN
     */
    protected function extractOgrn(array $b24Requisite, bool $isIp): ?string
    {
        Log::info('=== EXTRACTING OGRN ===', [
            'is_ip' => $isIp,
            'preset_id' => $b24Requisite['PRESET_ID'] ?? null,
            'has_RQ_OGRNIP' => isset($b24Requisite['RQ_OGRNIP']),
            'RQ_OGRNIP_raw' => $b24Requisite['RQ_OGRNIP'] ?? 'NOT SET',
            'RQ_OGRNIP_type' => gettype($b24Requisite['RQ_OGRNIP'] ?? null),
            'has_RQ_OGRN' => isset($b24Requisite['RQ_OGRN']),
            'RQ_OGRN_raw' => $b24Requisite['RQ_OGRN'] ?? 'NOT SET',
        ]);

        // Для ИП проверяем ОГРНИП
        if ($isIp) {
            $ogrnip = $this->cleanString($b24Requisite['RQ_OGRNIP'] ?? null);
            if ($ogrnip) {
                Log::debug('Extracted OGRNIP for IP', [
                    'value' => $ogrnip,
                    'requisite_id' => $b24Requisite['ID'] ?? null,
                ]);
                return $ogrnip;
            }
        }

        // Для ЮЛ или если у ИП нет ОГРНИП — проверяем ОГРН
        $ogrn = $this->cleanString($b24Requisite['RQ_OGRN'] ?? null);
        if ($ogrn) {
            Log::debug('Extracted OGRN', [
                'value' => $ogrn,
                'is_ip' => $isIp,
                'requisite_id' => $b24Requisite['ID'] ?? null,
            ]);
            return $ogrn;
        }

        // Если ничего не нашли — логируем
        if ($isIp) {
            Log::debug('No OGRN/OGRNIP found for IP', [
                'requisite_id' => $b24Requisite['ID'] ?? null,
                'has_RQ_OGRN' => isset($b24Requisite['RQ_OGRN']),
                'has_RQ_OGRNIP' => isset($b24Requisite['RQ_OGRNIP']),
                'RQ_OGRN_value' => $b24Requisite['RQ_OGRN'] ?? 'not set',
                'RQ_OGRNIP_value' => $b24Requisite['RQ_OGRNIP'] ?? 'not set',
            ]);
        }

        return null;
    }

    /**
     * Получить данные компании-контейнера
     */
    protected function fetchCompanyData(int $companyId): ?array
    {
        try {
            $result = $this->b24Service->call('crm.company.get', [
                'id' => $companyId,
            ]);

            if (empty($result['result'])) {
                Log::warning('Company not found in B24', ['company_id' => $companyId]);
                return null;
            }

            $company = $result['result'];

            $data = [
                'phone' => $this->extractFirstPhone($company),
                'email' => $this->extractFirstEmail($company),
                'comment' => $this->cleanString($company['COMMENTS'] ?? null),
            ];

            // 🆕 Ответственный через getUserInfo (аналогично InvoiceMapper)
            if (!empty($company['ASSIGNED_BY_ID'])) {
                $userInfo = $this->getUserInfo((int) $company['ASSIGNED_BY_ID']);

                if ($userInfo && !empty($userInfo['guid_1c'])) {
                    $data['responsible_guid_1c'] = $userInfo['guid_1c'];

                    Log::debug('Responsible GUID set for counterparty', [
                        'company_id' => $companyId,
                        'user_id' => $company['ASSIGNED_BY_ID'],
                        'guid' => $userInfo['guid_1c'],
                    ]);
                }
            }

            return $data;

        } catch (\Exception $e) {
            Log::error('Failed to fetch company data', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 🆕 Получить информацию о пользователе (с кэшированием)
     *
     * Копия из B24InvoiceMapper для консистентности
     */
    protected function getUserInfo(int $userId): ?array
    {
        try {
            return Cache::remember("b24:user:{$userId}", 3600, function () use ($userId) {
                $response = $this->b24Service->call('user.get', [
                    'ID' => $userId,
                ]);

                if (empty($response['result'][0])) {
                    Log::warning('User not found in B24', ['user_id' => $userId]);
                    return null;
                }

                $user = $response['result'][0];

                // Формируем полное имя
                $name = trim(($user['NAME'] ?? '') . ' ' . ($user['LAST_NAME'] ?? ''));
                if (empty($name)) {
                    $name = $user['EMAIL'] ?? "User #{$userId}";
                }

                // Поиск GUID в различных вариантах полей
                $guid1c = $this->findUserGuidField($user);

                return [
                    'name' => $name,
                    'guid_1c' => $guid1c,
                ];
            });

        } catch (\Exception $e) {
            Log::error('Failed to fetch user from B24', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 🆕 Найти поле с GUID пользователя
     */
    protected function findUserGuidField(array $user): ?string
    {
        // Список возможных названий (в порядке приоритета)
        $possibleFields = [
            'UF_USR_1C_GUID',
            'UF_1C_GUID',
            'UF_GUID_1C',
            'UF_USR_GUID_1C',
        ];

        // Сначала проверяем известные поля
        foreach ($possibleFields as $field) {
            if (!empty($user[$field])) {
                return (string) $user[$field];
            }
        }

        // Если не нашли — ищем любое поле содержащее "GUID" и "1C"
        foreach ($user as $key => $value) {
            if (empty($value)) {
                continue;
            }

            $keyUpper = strtoupper($key);

            if (str_contains($keyUpper, 'GUID') && str_contains($keyUpper, '1C')) {
                Log::info('Found user GUID in non-standard field', [
                    'field' => $key,
                    'value' => $value,
                ]);
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Извлечь первый телефон
     */
    protected function extractFirstPhone(array $b24Company): ?string
    {
        if (empty($b24Company['PHONE'])) {
            return null;
        }

        if (is_array($b24Company['PHONE']) && isset($b24Company['PHONE'][0]['VALUE'])) {
            return $this->cleanString($b24Company['PHONE'][0]['VALUE']);
        }

        return null;
    }

    /**
     * Извлечь первый email
     */
    protected function extractFirstEmail(array $b24Company): ?string
    {
        if (empty($b24Company['EMAIL'])) {
            return null;
        }

        if (is_array($b24Company['EMAIL']) && isset($b24Company['EMAIL'][0]['VALUE'])) {
            return $this->cleanString($b24Company['EMAIL'][0]['VALUE']);
        }

        return null;
    }

    /**
     * Очистка строки
     */
    protected function cleanString(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $cleaned = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        // 🆕 Дополнительная проверка: если после очистки пустая строка — возвращаем null
        return $cleaned !== '' ? $cleaned : null;
    }
}
