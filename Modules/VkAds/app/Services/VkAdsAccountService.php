<?php

namespace Modules\VkAds\app\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\VkAds\app\Models\VkAdsAccount;
use Illuminate\Support\Facades\Log;

class VkAdsAccountService
{
    private VkAdsApiService $apiService;

    public function __construct(VkAdsApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    public function createAccount(array $data): VkAdsAccount
    {
        return VkAdsAccount::create($data);
    }

    public function getAccounts(): Collection
    {
        return VkAdsAccount::with(['organization', 'contract', 'campaigns'])->get();
    }

    public function getAccount(int $id): VkAdsAccount
    {
        return VkAdsAccount::with(['organization', 'contract', 'campaigns.adGroups'])
            ->findOrFail($id);
    }

    public function updateAccount(int $id, array $data): VkAdsAccount
    {
        $account = VkAdsAccount::findOrFail($id);
        $account->update($data);
        return $account;
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
                Log::info("Synced agency clients", ['count' => count($clients)]);
            } else {
                // Для клиентского аккаунта синхронизируем его данные через кампании
                Log::info("Client account sync - will sync through campaigns");
            }

            // ИСПРАВЛЕНО: обновляем время синхронизации для самого аккаунта
            $account->update(['last_sync_at' => now()]);

        } catch (\Exception $e) {
            Log::warning("Failed to sync account {$account->id}: " . $e->getMessage());
            $account->update(['last_sync_at' => now()]);
        }

        return $account;
    }

    public function syncAgencyClients(VkAdsAccount $agencyAccount): array
    {
        if (!$agencyAccount->isAgency()) {
            throw new \Exception('Only agency accounts can sync clients');
        }

        try {
            $vkClients = $this->apiService->makeAuthenticatedRequest($agencyAccount, 'agency/clients');
            $clients = [];

            Log::info("Found VK clients", ['count' => count($vkClients), 'list' => $vkClients]);

            foreach ($vkClients as $vkClient) {
                $accountData = $vkClient['user']['account'] ?? [];
                $userData = $vkClient['user'] ?? [];

                // ИСПРАВЛЕНО: сохраняем и account_id и user_id
                $client = VkAdsAccount::updateOrCreate([
                    'vk_account_id' => $accountData['id'] ?? $userData['id']
                ], [
                    'vk_user_id' => $userData['id'], // ДОБАВЛЕНО: ID пользователя для токенов
                    'vk_username' => $userData['username'], // ДОБАВЛЕНО: username для токенов
                    'account_name' => $userData['client_username'] ?? 'Client ' . ($accountData['id'] ?? $userData['id']),
                    'account_type' => 'client',
                    'account_status' => $this->mapVkAccountStatus($vkClient['status'] ?? 'active'),
                    'balance' => isset($accountData['balance']) ? (float)$accountData['balance'] : 0,
                    'currency' => $accountData['currency'] ?? 'RUB',
                    'last_sync_at' => now()
                ]);

                $clients[] = $client;
                Log::info("Synced client", [
                    'id' => $client->id,
                    'vk_account_id' => $client->vk_account_id,
                    'vk_user_id' => $client->vk_user_id,
                    'vk_username' => $client->vk_username,
                    'name' => $client->account_name,
                    'balance' => $client->balance
                ]);
            }

            return $clients;

        } catch (\Exception $e) {
            Log::error("Failed to sync agency clients: " . $e->getMessage());
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
                    'vk_creative_id' => $vkCreative['id']
                ], [
                    'vk_ads_account_id' => $account->id,
                    'name' => $vkCreative['name'] ?? 'Creative ' . $vkCreative['id'],
                    'creative_type' => $this->mapCreativeType($vkCreative['type'] ?? 'image'),
                    'width' => $vkCreative['width'] ?? null,
                    'height' => $vkCreative['height'] ?? null,
                    'duration' => $vkCreative['duration'] ?? null,
                    'last_sync_at' => now()
                ]);

                $creatives[] = $creative;
            }

            return $creatives;

        } catch (\Exception $e) {
            Log::warning("Failed to sync creatives for account {$account->id}: " . $e->getMessage());
            return [];
        }
    }

    private function mapVkAccountStatus($status): string
    {
        return match($status) {
            'active', 1 => 'active',
            'blocked', 'suspended', 0 => 'blocked',
            'deleted', -1 => 'deleted',
            default => 'active'
        };
    }

    private function mapCreativeType($type): string
    {
        return match($type) {
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
}
