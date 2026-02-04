<?php

namespace Modules\Bitrix24\app\Services\Mappers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\Contract;
use Modules\Accounting\app\Models\Counterparty;
use Modules\Accounting\app\Models\Organization;
use Modules\Bitrix24\app\Exceptions\DependencyNotReadyException;
use Modules\Bitrix24\app\Services\Bitrix24Service;

class B24InvoiceMapper
{
    protected Bitrix24Service $b24Service;

    public function __construct(Bitrix24Service $b24Service)
    {
        $this->b24Service = $b24Service;
    }

    /**
     * Маппинг счета B24 → CustomerOrder
     */
    public function map(array $b24Invoice): array
    {
        $data = [
            'number' => $this->extractNumber($b24Invoice['title'] ?? null),
            'date' => $this->parseDate($b24Invoice['begindate'] ?? null),
            'amount' => (float) ($b24Invoice['opportunity'] ?? 0),
            'currency_guid_1c' => 'f1a17773-5488-11e0-91e9-00e04c771318',
            'settlement_currency_guid_1c' => 'f1a17773-5488-11e0-91e9-00e04c771318',
            'comment' => $this->cleanString($b24Invoice['comments'] ?? null),
            'amount_includes_vat' => true, // По умолчанию с НДС,
            'exchange_rate' => '1.000000',
            'exchange_multiplier' => '1.000000',
            'organization_bank_account_guid_1c' => '9ec1feea-6136-11dd-8753-de071bdd34b1',

        ];

        // Связь с контрагентом (ОБЯЗАТЕЛЬНА!)
        if (!empty($b24Invoice['companyId'])) {
            $counterpartyGuid = $this->findCounterpartyGuidByCompanyId($b24Invoice['companyId']);

            if (!$counterpartyGuid) {
                throw new DependencyNotReadyException(
                    "Counterparty not synced yet for company ID: {$b24Invoice['companyId']}"
                );
            }

            $data['counterparty_guid_1c'] = $counterpartyGuid;
        } else {
            throw new DependencyNotReadyException(
                "Invoice has no companyId: {$b24Invoice['id']}"
            );
        }

        // Связь с организацией (опционально)
        if (!empty($b24Invoice['mycompanyId'])) {
            $organizationGuid = $this->findOrganizationGuidByCompanyId($b24Invoice['mycompanyId']);

            if ($organizationGuid) {
                $data['organization_guid_1c'] = $organizationGuid;

                // Находим локальную организацию
                $organization = Organization::where('guid_1c', $organizationGuid)->first();
                if ($organization) {
                    $data['organization_id'] = $organization->id;
                }
            }
        }

        // Связь с договором (опционально)
        if (!empty($b24Invoice['parentId1064'])) {
            $contractGuid = $this->findContractGuidById($b24Invoice['parentId1064']);

            if ($contractGuid) {
                $data['contract_guid_1c'] = $contractGuid;
            }
        }

        // Ответственный (опционально)
        if (!empty($b24Invoice['assignedById'])) {
            $userInfo = $this->getUserInfo($b24Invoice['assignedById']);

            if ($userInfo) {
                $data['responsible_name'] = $userInfo['name'];
                $data['responsible_guid_1c'] = $userInfo['guid_1c'];
            }
        }

        return $data;
    }


    /**
     * Получить информацию о пользователе (с кэшированием)
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

                // Ищем поле GUID 1C (может называться по-разному)
                $guid1c = null;
                foreach ($user as $key => $value) {
                    if (stripos($key, 'GUID') !== false && stripos($key, '1C') !== false) {
                        $guid1c = $value;
                        break;
                    }
                }

                // Если не нашли автоматически, пробуем стандартные варианты
                if (!$guid1c) {
                    $guid1c = $user['UF_GUID_1C']
                        ?? $user['UF_USR_GUID_1C']
                        ?? $user['UF_USR_1C_GUID']
                        ?? $user['UF_1C_GUID']
                        ?? null;
                }

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
     * Извлечь номер из заголовка (формат: "Счёт №123 от 01.01.2025")
     */
    protected function extractNumber(?string $title): ?string
    {
        if (!$title) {
            return null;
        }

        // Пробуем извлечь номер по паттерну
        if (preg_match('/№\s*([^\s]+)/', $title, $matches)) {
            return $matches[1];
        }

        // Если не получилось - возвращаем весь title
        return $this->cleanString($title);
    }

    /**
     * Найти GUID контрагента по ID компании
     */
    protected function findCounterpartyGuidByCompanyId(int $companyId): ?string
    {
        try {
            $response = $this->b24Service->call('crm.requisite.list', [
                'filter' => [
                    'ENTITY_TYPE_ID' => 4,
                    'ENTITY_ID' => $companyId,
                ],
                'select' => ['ID', 'UF_CRM_GUID_1C'],
                'limit' => 1,
            ]);

            if (empty($response['result'][0])) {
                return null;
            }

            $requisite = $response['result'][0];

            if (!empty($requisite['UF_CRM_GUID_1C'])) {
                return $requisite['UF_CRM_GUID_1C'];
            }

            // Ищем локально
            $requisiteId = $requisite['ID'];
            $counterparty = Counterparty::where('b24_id', $requisiteId)->first();

            return $counterparty?->guid_1c;

        } catch (\Exception $e) {
            Log::error('Failed to find counterparty GUID', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Найти GUID организации по ID "Моей компании"
     */
    protected function findOrganizationGuidByCompanyId(int $companyId): ?string
    {
        try {
            $response = $this->b24Service->call('crm.requisite.list', [
                'filter' => [
                    'ENTITY_TYPE_ID' => 4,
                    'ENTITY_ID' => $companyId,
                ],
                'select' => ['ID', 'UF_CRM_GUID_1C'],
                'limit' => 1,
            ]);

            if (empty($response['result'][0])) {
                return null;
            }

            $requisite = $response['result'][0];

            if (!empty($requisite['UF_CRM_GUID_1C'])) {
                return $requisite['UF_CRM_GUID_1C'];
            }

            // Ищем локально
            $requisiteId = $requisite['ID'];
            $organization = Organization::where('b24_id', $requisiteId)->first();

            return $organization?->guid_1c;

        } catch (\Exception $e) {
            Log::error('Failed to find organization GUID', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Найти GUID договора по ID в B24
     */
    protected function findContractGuidById(int $contractB24Id): ?string
    {
        // Сначала ищем локально
        $contract = Contract::where('b24_id', $contractB24Id)->first();

        if ($contract && $contract->guid_1c) {
            return $contract->guid_1c;
        }

        // Если не найден локально - запрашиваем из B24
        try {
            $response = $this->b24Service->call('crm.item.get', [
                'entityTypeId' => 1064,
                'id' => $contractB24Id,
            ]);

            $b24Contract = $response['result']['item'] ?? null;

            if (!empty($b24Contract['ufCrm_19_GUID_1C'])) {
                return $b24Contract['ufCrm_19_GUID_1C'];
            }

            return null;

        } catch (\Exception $e) {
            Log::warning('Failed to fetch contract from B24', [
                'contract_id' => $contractB24Id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Маппинг валюты
     */
    protected function mapCurrency(string $currencyCode): ?string
    {
        return 'f1a17773-5488-11e0-91e9-00e04c771318';
    }

    /**
     * Парсинг даты
     */
    protected function parseDate(?string $dateStr): ?\Carbon\Carbon
    {
        if (!$dateStr) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($dateStr);
        } catch (\Exception $e) {
            Log::warning('Failed to parse date', ['date' => $dateStr]);
            return null;
        }
    }

    /**
     * Очистка строки
     */
    protected function cleanString(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
