<?php

namespace Modules\VkAds\app\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\Contract;
use Modules\VkAds\app\Exceptions\VkAdsException;
use Modules\VkAds\app\Models\VkAdsAccount;

class VkAdsAccountService
{
    private VkAdsApiService $apiService;

    public function __construct(VkAdsApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    /**
     * Создать или обновить рекламный кабинет на основе договора
     */
    public function createOrUpdateAccountFromContract(int $contractId, array $additionalData = []): VkAdsAccount
    {
        Log::info('Creating or updating VK Ads account from contract', [
            'contract_id' => $contractId,
            'additional_data' => $additionalData,
        ]);

        // Получаем договор с контрагентом
        $contract = Contract::with('counterparty')->findOrFail($contractId);

        if (! $contract->counterparty) {
            throw new VkAdsException("Contract {$contractId} has no associated counterparty");
        }

        // Проверяем существующие кабинеты для этого контрагента
        $existingAccounts = VkAdsAccount::where('counterparty_id', $contract->counterparty->id)
            ->where('account_type', 'client')
            ->whereNot('status', 'deleted')
            ->get();

        // 1. Если есть кабинет с этим же договором - ничего не делаем
        $sameContractAccount = $existingAccounts->firstWhere('contract_id', $contractId);
        if ($sameContractAccount) {
            Log::info('Account with same contract already exists', [
                'account_id' => $sameContractAccount->id,
                'contract_id' => $contractId,
            ]);
            Log::info("Кабинет с этим договором уже существует (ID: {$sameContractAccount->id})");

            return $sameContractAccount;
        }

        // 2. Если есть кабинет с другой договором - обновляем договор в VK
        if ($existingAccounts->isNotEmpty()) {
            $existingAccount = $existingAccounts->first();
            Log::info('Updating existing account with new contract', [
                'account_id' => $existingAccount->id,
                'old_contract_id' => $existingAccount->contract_id,
                'new_contract_id' => $contractId,
            ]);

            return $this->updateAccountContract($existingAccount, $contract, $additionalData);
        }

        // 3. Если кабинетов нет - создаем новый
        Log::info('No existing accounts found, creating new one', [
            'counterparty_id' => $contract->counterparty->id,
            'contract_id' => $contractId,
        ]);

        return $this->createNewAccount($contract, $additionalData);
    }

    /**
     * Создать новый кабинет
     */
    private function createNewAccount(Contract $contract, array $additionalData): VkAdsAccount
    {
        // Получаем агентский аккаунт для создания клиентского
        $agencyAccount = $this->getAgencyAccount();

        return DB::transaction(function () use ($agencyAccount, $contract, $additionalData) {
            try {
                // 1. Подготавливаем данные для VK Ads API (ИСПРАВЛЕНО согласно документации)
                $vkAccountData = $this->prepareVkAccountData($contract, $additionalData);

                // 2. СНАЧАЛА создаем в VK Ads
                Log::info('Creating account in VK Ads', ['data' => $vkAccountData]);
                $vkResponse = $this->apiService->makeAuthenticatedRequest(
                    $agencyAccount,
                    'agency/clients',
                    $vkAccountData,
                    'POST'
                );
                Log::info('VK Ads account created successfully', [
                    'vk_response' => $vkResponse,
                    'vk_response_structure' => array_keys($vkResponse),
                    'user_data' => $vkResponse['user'] ?? null,
                ]);

                // 3. ЗАТЕМ создаем запись в нашей БД
                $localAccountData = $this->prepareLocalAccountData($vkResponse, $contract, $additionalData);

                Log::info('Creating local account record', ['data' => $localAccountData]);
                $account = VkAdsAccount::create($localAccountData);

                Log::info('VK Ads account created successfully', [
                    'id' => $account->id,
                    'vk_account_id' => $account->vk_account_id,
                    'name' => $account->account_name,
                    'contract_id' => $account->contract_id,
                    'counterparty_id' => $account->counterparty_id,
                ]);

                return $account;

            } catch (\Exception $e) {
                Log::error('Failed to create VK Ads account', [
                    'contract_id' => $contract->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                throw new VkAdsException(
                    'Failed to create VK Ads account: '.$e->getMessage(),
                    0,
                    $e,
                    ['contract_id' => $contract->id]
                );
            }
        });
    }

    /**
     * Подготовить данные для создания кабинета в VK Ads API (ИСПРАВЛЕНО согласно документации)
     */
    private function prepareVkAccountData(Contract $contract, array $additionalData): array
    {
        $counterparty = $contract->counterparty;

        if (! $counterparty) {
            throw new VkAdsException("Contract {$contract->id} has no associated counterparty");
        }

        // Согласно документации AgencyClient:
        // Обязательные поля: access_type, user
        // Опциональные: juridical_details, physical_details
        $vkData = [
            'access_type' => 'full_access', // Обязательное поле
        ];

        // Секция user согласно UserClient
        $vkData['user']['additional_info'] = [
            'client_name' => $additionalData['account_name'] ??
                    $counterparty->name ??
                    "Кабинет {$contract->number}",
            // Другие поля UserClient будут проигнорированы VK API при создании
        ];
        // Формат даты для VK API: Y-m-d
        $contractDate = $contract->date ? $contract->date->format('Y-m-d') : now()->format('Y-m-d');

        // Добавляем juridical_details или physical_details согласно документации
        if ($counterparty->entity_type === 'legal') {
            // ClientOrdJuridical для юридических лиц (резидентов РФ)
            $vkData['juridical_details'] = [
                'user_type' => 'juridical', // Обязательное поле
                'name' => $counterparty->sanitizeName(),
                'inn' => $counterparty->inn ?? '',
                'contract_date' => $contractDate, // Исправленный формат даты
                'contract_number' => $contract->number ?? 'Б/Н',
                'contract_subject' => 'org_distribution',
                'contract_type' => 'service',
                'vat' => true, // Обязательное поле!
            ];
        } else {
            // ClientOrdPhysical для физических лиц (резидентов РФ)
            $vkData['physical_details'] = [
                'user_type' => 'physical', // Обязательное поле
                'name' => $counterparty->sanitizeName(),
                'inn' => $counterparty->inn ?? '',
                'contract_date' => $contractDate, // Исправленный формат даты
                'contract_number' => $contract->number ?? 'Б/Н',
                'contract_subject' => 'org_distribution',
                'contract_type' => 'service',
                'vat' => true, // Обязательное поле!
            ];
        }

        Log::info('Prepared VK account data according to AgencyClient spec', ['data' => $vkData]);

        return $vkData;
    }

    /**
     * Подготовить данные для сохранения в локальной БД (ИСПРАВЛЕНО)
     */
    private function prepareLocalAccountData(array $vkResponse, Contract $contract, array $additionalData): array
    {
        $counterparty = $contract->counterparty;

        // Согласно структуре ответа VK API
        $userData = $vkResponse['user'] ?? [];

        // Извлекаем ID аккаунта из user.id
        $vkAccountId = $userData['id'] ?? null;

        // Если не нашли ID аккаунта, это критическая ошибка
        if (! $vkAccountId) {
            Log::error('Failed to extract vk_account_id from VK response', ['response' => $vkResponse]);
            throw new VkAdsException('Failed to extract vk_account_id from VK response');
        }

        return [
            'vk_account_id' => $vkAccountId, // Используем user.id как vk_account_id
            'vk_user_id' => $userData['id'] ?? null,
            'vk_username' => $userData['client_username'] ?? $userData['username'] ?? null,
            'account_name' => $userData['client_username'] ??
                ($additionalData['account_name'] ?? $counterparty->name),
            'account_type' => 'client',
            'account_status' => $this->mapVkAccountStatus($vkResponse['status'] ?? 'active'),
            'counterparty_id' => $counterparty->id,
            'contract_id' => $contract->id,
            'balance' => 0, // Новый аккаунт имеет нулевой баланс
            'currency' => 'RUB',
            'access_roles' => $vkResponse['access_type'] ?? null,
            'can_view_budget' => false, // Новый аккаунт пока без доступа к бюджету
            'last_sync_at' => now(),
            'sync_enabled' => true,
        ];
    }

    /**
     * Синхронизировать аккаунт с VK Ads
     */
    public function syncAccount(int $accountId): VkAdsAccount
    {
        $account = VkAdsAccount::findOrFail($accountId);

        try {
            if ($account->isAgency()) {
                // Для агентского аккаунта получаем список клиентов
                $clients = $this->syncAgencyClients($account);
                Log::info('Synced agency clients', ['count' => count($clients)]);
            } else {
                // Для клиентского аккаунта синхронизируем его данные через кампании
                Log::info('Client account sync - will sync through campaigns');
            }

            // ИСПРАВЛЕНО: обновляем время синхронизации для самого аккаунта
            $account->update(['last_sync_at' => now()]);

        } catch (\Exception $e) {
            Log::warning("Failed to sync account {$account->id}: ".$e->getMessage());
            $account->update(['last_sync_at' => now()]);
        }

        return $account;
    }

    public function syncAgencyClients(VkAdsAccount $agencyAccount): array
    {
        if (! $agencyAccount->isAgency()) {
            throw new \Exception('Only agency accounts can sync clients');
        }

        try {
            $vkClients = $this->apiService->makeAuthenticatedRequest($agencyAccount, 'agency/clients');
            $clients = [];

            Log::info('Found VK clients', ['count' => count($vkClients), 'list' => $vkClients]);

            foreach ($vkClients as $vkClient) {
                $accountData = $vkClient['user']['account'] ?? [];
                $userData = $vkClient['user'] ?? [];

                // ИСПРАВЛЕНО: сохраняем и account_id и user_id
                $client = VkAdsAccount::updateOrCreate([
                    'vk_user_id' => $userData['id'],
                ], [
                    'vk_account_id' => $accountData['id'],
                    'vk_user_id' => $userData['id'], // ДОБАВЛЕНО: ID пользователя для токенов
                    'vk_username' => $userData['username'], // ДОБАВЛЕНО: username для токенов
                    'account_name' => $userData['client_username'] ?? 'Client '.($accountData['id'] ?? $userData['id']),
                    'account_type' => 'client',
                    'account_status' => str_ends_with($userData['username'] ?? '', 'deleted')
                        ? 'deleted'
                        : $this->mapVkAccountStatus($accountData['status'] ?? 'active'),

                    'balance' => isset($accountData['balance']) ? (float) $accountData['balance'] : 0,
                    'currency' => $accountData['currency'] ?? 'RUB',
                    'last_sync_at' => now(),
                ]);

                $clients[] = $client;
                Log::info('Synced client', [
                    'id' => $client->id,
                    'vk_account_id' => $client->vk_account_id,
                    'vk_user_id' => $client->vk_user_id,
                    'vk_username' => $client->vk_username,
                    'name' => $client->account_name,
                    'balance' => $client->balance,
                ]);
            }

            return $clients;

        } catch (\Exception $e) {
            Log::error('Failed to sync agency clients: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Синхронизировать креативы аккаунта
     */
    public function syncCreatives(VkAdsAccount $account): array
    {
        try {
            $vkCreatives = $this->apiService->makeAuthenticatedRequest($account, 'creatives');
            $creatives = [];

            foreach ($vkCreatives as $vkCreative) {
                $creative = \Modules\VkAds\app\Models\VkAdsCreative::updateOrCreate([
                    'vk_creative_id' => $vkCreative['id'],
                ], [
                    'vk_ads_account_id' => $account->id,
                    'name' => $vkCreative['name'] ?? 'Creative '.$vkCreative['id'],
                    'creative_type' => $this->mapCreativeType($vkCreative['type'] ?? 'image'),
                    'width' => $vkCreative['width'] ?? null,
                    'height' => $vkCreative['height'] ?? null,
                    'duration' => $vkCreative['duration'] ?? null,
                    'last_sync_at' => now(),
                ]);

                $creatives[] = $creative;
            }

            return $creatives;

        } catch (\Exception $e) {
            Log::warning("Failed to sync creatives for account {$account->id}: ".$e->getMessage());

            return [];
        }
    }

    private function mapVkAccountStatus($status): string
    {
        return match ($status) {
            'active', 1 => 'active',
            'blocked', 'suspended', 0 => 'blocked',
            'deleted', -1 => 'deleted',
            default => 'active'
        };
    }

    private function mapCreativeType($type): string
    {
        return match ($type) {
            'video', 'video_file' => 'video',
            'image', 'banner' => 'image',
            default => 'image'
        };
    }

    private function getAgencyAccount(): VkAdsAccount
    {
        return VkAdsAccount::where('account_type', 'agency')
            ->where('id', 1)
            ->firstOrFail();
    }

    private function updateAccountContract(VkAdsAccount $account, Contract $newContract, array $additionalData): VkAdsAccount
    {
        return DB::transaction(function () use ($account, $newContract, $additionalData) {
            try {
                // 1. Обновляем информацию в VK Ads (если API поддерживает)
                Log::info('Updating account info in VK Ads (if supported)', [
                    'account_id' => $account->id,
                    'vk_account_id' => $account->vk_account_id,
                    'new_contract_id' => $newContract->id,
                ]);

                // 2. Обновляем запись в нашей БД
                $account->update([
                    'contract_id' => $newContract->id,
                    'counterparty_id' => $newContract->counterparty->id,
                    'account_name' => $additionalData['account_name'] ?? $account->account_name,
                    'last_sync_at' => now(),
                ]);

                Log::info('Account contract updated successfully', [
                    'id' => $account->id,
                    'contract_id' => $account->contract_id,
                    'counterparty_id' => $account->counterparty_id,
                ]);

                return $account;

            } catch (\Exception $e) {
                Log::error('Failed to update account contract', [
                    'account_id' => $account->id,
                    'new_contract_id' => $newContract->id,
                    'error' => $e->getMessage(),
                ]);

                throw new VkAdsException(
                    'Failed to update account contract: '.$e->getMessage(),
                    0,
                    $e,
                    ['account_id' => $account->id, 'contract_id' => $newContract->id]
                );
            }
        });
    }
}
