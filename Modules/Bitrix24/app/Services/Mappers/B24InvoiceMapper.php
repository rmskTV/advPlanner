<?php

namespace Modules\Bitrix24\app\Services\Mappers;

use AllowDynamicProperties;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\Contract;
use Modules\Accounting\app\Models\Counterparty;
use Modules\Accounting\app\Models\Organization;
use Modules\Bitrix24\app\Exceptions\DependencyNotReadyException;
use Modules\Bitrix24\app\Services\Bitrix24Service;

#[AllowDynamicProperties] class B24InvoiceMapper
{
    protected Bitrix24Service $b24Service;

    /**
     * Strict mode: выбрасывать исключения если зависимости не найдены
     */
    protected bool $strictMode = true;
    public function __construct(Bitrix24Service $b24Service)
    {
        $this->b24Service = $b24Service;
    }

    /**
     * Установить режим строгой проверки
     */
    public function setStrictMode(bool $strict): self
    {
        $this->strictMode = $strict;
        return $this;
    }


    /**
     * 🆕 Установить предварительно разрешённые зависимости
     */
    public function setResolvedDependencies(array $dependencies): self
    {
        $this->resolvedDependencies = $dependencies;
        return $this;
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
            'amount_includes_vat' => true,
            'exchange_rate' => '1.000000',
            'exchange_multiplier' => '1.000000',
            'organization_bank_account_guid_1c' => '9ec1feea-6136-11dd-8753-de071bdd34b1',
        ];

        // ═══════════════════════════════════════════════════════════════
        // КОНТРАГЕНТ
        // ═══════════════════════════════════════════════════════════════

        // 🆕 Сначала проверяем предварительно разрешённые зависимости
        $counterpartyGuid = $this->resolvedDependencies['counterparty_guid'] ?? null;

        // Если не передан — пытаемся найти сами (fallback)
        if (!$counterpartyGuid && !empty($b24Invoice['companyId'])) {
            $counterpartyGuid = $this->findCounterpartyGuidByCompanyId($b24Invoice['companyId']);
        }

        if ($counterpartyGuid) {
            $data['counterparty_guid_1c'] = $counterpartyGuid;
        } elseif ($this->strictMode) {
            throw new DependencyNotReadyException(
                "Counterparty not found for invoice: {$b24Invoice['id']}"
            );
        } else {
            Log::warning('Counterparty GUID not found for invoice', [
                'invoice_id' => $b24Invoice['id'] ?? null,
                'company_id' => $b24Invoice['companyId'] ?? null,
            ]);
        }

        // ═══════════════════════════════════════════════════════════════
        // ОРГАНИЗАЦИЯ (mycompanyId)
        // ═══════════════════════════════════════════════════════════════

        if (!empty($b24Invoice['mycompanyId'])) {
            $organizationGuid = $this->findOrganizationGuidByCompanyId($b24Invoice['mycompanyId']);

            if ($organizationGuid) {
                $data['organization_guid_1c'] = $organizationGuid;

                $organization = Organization::where('guid_1c', $organizationGuid)->first();
                if ($organization) {
                    $data['organization_id'] = $organization->id;
                }
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // ДОГОВОР
        // ═══════════════════════════════════════════════════════════════

        // 🆕 Сначала проверяем предварительно разрешённые зависимости
        $contractGuid = $this->resolvedDependencies['contract_guid'] ?? null;

        // Если не передан — пытаемся найти сами (fallback)
        if (!$contractGuid && !empty($b24Invoice['parentId1064'])) {
            $contractGuid = $this->findContractGuidById($b24Invoice['parentId1064']);
        }

        if ($contractGuid) {
            $data['contract_guid_1c'] = $contractGuid;

            Log::debug('Contract GUID set for invoice', [
                'invoice_id' => $b24Invoice['id'] ?? null,
                'contract_guid' => $contractGuid,
            ]);
        } else {
            Log::info('No contract GUID for invoice', [
                'invoice_id' => $b24Invoice['id'] ?? null,
                'parent_id_1064' => $b24Invoice['parentId1064'] ?? null,
                'resolved_contract_guid' => $this->resolvedDependencies['contract_guid'] ?? 'not set',
            ]);
        }

        // ═══════════════════════════════════════════════════════════════
        // ОТВЕТСТВЕННЫЙ
        // ═══════════════════════════════════════════════════════════════

        if (!empty($b24Invoice['assignedById'])) {
            $userInfo = $this->getUserInfo($b24Invoice['assignedById']);

            if ($userInfo) {
                $data['responsible_name'] = $userInfo['name'];

                // 🆕 Только если GUID реально есть
                if (!empty($userInfo['guid_1c'])) {
                    $data['responsible_guid_1c'] = $userInfo['guid_1c'];
                } else {
                    Log::debug('User has no GUID_1C', [
                        'user_id' => $b24Invoice['assignedById'],
                        'user_name' => $userInfo['name'],
                    ]);
                }
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

                // 🆕 Проверяем все возможные варианты поля GUID
                $guid1c = $user['UF_GUID_1C']
                    ?? $user['UF_USR_GUID_1C']
                    ?? $user['UF_USR_1C_GUID']  // ← ДОБАВЛЕНО!
                    ?? $user['UF_1C_GUID']
                    ?? null;

                // 🆕 Логируем для отладки (временно)
                if (!$guid1c) {
                    Log::debug('User GUID not found, available UF fields', [
                        'user_id' => $userId,
                        'user_name' => $name,
                        'uf_fields' => array_filter($user, fn($key) => str_starts_with($key, 'UF_'), ARRAY_FILTER_USE_KEY),
                    ]);
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

        if (preg_match('/№\s*([^\s]+)/', $title, $matches)) {
            return $matches[1];
        }

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
        $contract = Contract::where('b24_id', $contractB24Id)->first();

        if ($contract && $contract->guid_1c) {
            return $contract->guid_1c;
        }

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
