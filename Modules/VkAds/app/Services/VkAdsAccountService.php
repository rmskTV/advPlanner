<?php

namespace Modules\VkAds\app\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Accounting\app\Models\Contract;
use Modules\Accounting\app\Models\Organization;
use Modules\VkAds\app\Models\VkAdsAccount;

class VkAdsAccountService
{
    private VkAdsApiService $apiService;

    public function __construct(VkAdsApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    // === СОЗДАНИЕ АККАУНТОВ ===

    /**
     * Создать агентский кабинет для организации
     */
    public function createAgencyAccount(Organization $organization, array $vkAccountData): VkAdsAccount
    {
        return VkAdsAccount::create([
            'vk_account_id' => $vkAccountData['account_id'],
            'account_name' => $vkAccountData['account_name'],
            'account_type' => 'agency',
            'organization_id' => $organization->id,
            'account_status' => 'active',
        ]);
    }

    /**
     * Создать клиентский кабинет для договора
     */
    public function createClientAccount(Contract $contract, array $vkAccountData): VkAdsAccount
    {
        return VkAdsAccount::create([
            'vk_account_id' => $vkAccountData['account_id'],
            'account_name' => $vkAccountData['account_name'],
            'account_type' => 'client',
            'contract_id' => $contract->id,
            'account_status' => 'active',
        ]);
    }

    // === CRUD ОПЕРАЦИИ ===

    public function createAccount(array $accountData): VkAdsAccount
    {
        return VkAdsAccount::create($accountData);
    }

    public function getAccounts(array $filters = []): Collection
    {
        $query = VkAdsAccount::with(['organization', 'contract.counterparty']);

        if (isset($filters['account_type'])) {
            $query->where('account_type', $filters['account_type']);
        }

        if (isset($filters['account_status'])) {
            $query->where('account_status', $filters['account_status']);
        }

        return $query->get();
    }

    public function updateAccount(int $accountId, array $data): VkAdsAccount
    {
        $account = VkAdsAccount::findOrFail($accountId);
        $account->update($data);

        return $account;
    }

    public function deleteAccount(int $accountId): bool
    {
        return VkAdsAccount::findOrFail($accountId)->delete();
    }

    // === СИНХРОНИЗАЦИЯ С VK ===

    public function syncAccountFromVk(int $vkAccountId): VkAdsAccount
    {
        $account = VkAdsAccount::where('vk_account_id', $vkAccountId)->firstOrFail();

        $vkData = $this->apiService->makeAuthenticatedRequest($account, 'accounts.get', [
            'account_ids' => [$vkAccountId],
        ]);

        if (! empty($vkData)) {
            $account->update([
                'account_name' => $vkData[0]['account_name'],
                'account_status' => $vkData[0]['account_status'],
                'balance' => $vkData[0]['balance'] / 100, // VK возвращает в копейках
                'last_sync_at' => now(),
            ]);
        }

        return $account;
    }

    public function syncAllAccounts(): Collection
    {
        $accounts = VkAdsAccount::where('sync_enabled', true)->get();

        foreach ($accounts as $account) {
            try {
                $this->syncAccountFromVk($account->vk_account_id);
            } catch (\Exception $e) {
                // Логируем ошибку, но продолжаем синхронизацию других аккаунтов
                \Log::error("Failed to sync account {$account->id}: ".$e->getMessage());
            }
        }

        return $accounts;
    }

    public function getAccountBalance(VkAdsAccount $account): array
    {
        return $this->apiService->makeAuthenticatedRequest($account, 'accounts.getBalance', [
            'account_id' => $account->vk_account_id,
        ]);
    }

    // === ПОЛУЧЕНИЕ С EAGER LOADING ===

    public function getAccountsWithAccounting(): Collection
    {
        return VkAdsAccount::with([
            'organization',
            'contract.counterparty',
            'campaigns.adGroups.orderItem.customerOrder',
        ])->get();
    }
}
