<?php

namespace Modules\VkAds\app\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\VkAds\app\DTOs\BudgetUpdateDTO;
use Modules\VkAds\app\DTOs\CampaignFiltersDTO;
use Modules\VkAds\app\DTOs\CreateCampaignDTO;
use Modules\VkAds\app\DTOs\UpdateCampaignDTO;
use Modules\VkAds\app\Models\VkAdsAccount;
use Modules\VkAds\app\Models\VkAdsCampaign;

class VkAdsCampaignService
{
    private VkAdsApiService $apiService;

    public function __construct(VkAdsApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    // === CRUD КАМПАНИЙ ===

    public function createCampaign(VkAdsAccount $account, CreateCampaignDTO $data): VkAdsCampaign
    {
        // Создаем кампанию в VK Ads
        $vkResponse = $this->apiService->makeAuthenticatedRequest($account, 'campaigns.create', [
            'account_id' => $account->vk_account_id,
            'name' => $data->name,
            'campaign_type' => $data->campaignType,
            'daily_budget' => $data->dailyBudget ? $data->dailyBudget * 100 : null, // VK принимает в копейках
            'start_date' => $data->startDate->format('Y-m-d'),
        ]);

        // Сохраняем в БД
        return VkAdsCampaign::create([
            'vk_campaign_id' => $vkResponse['id'],
            'vk_ads_account_id' => $account->id,
            'name' => $data->name,
            'description' => $data->description,
            'campaign_type' => $data->campaignType,
            'daily_budget' => $data->dailyBudget,
            'total_budget' => $data->totalBudget,
            'start_date' => $data->startDate,
            'end_date' => $data->endDate,
            'status' => 'active',
            'vk_data' => $vkResponse,
        ]);
    }

    public function getCampaigns(VkAdsAccount $account, CampaignFiltersDTO $filters): Collection
    {
        $query = VkAdsCampaign::where('vk_ads_account_id', $account->id)
            ->with(['adGroups.orderItem']);

        if ($filters->status) {
            $query->where('status', $filters->status);
        }

        if ($filters->campaignType) {
            $query->where('campaign_type', $filters->campaignType);
        }

        return $query->get();
    }

    public function getCampaign(int $campaignId): VkAdsCampaign
    {
        return VkAdsCampaign::with(['account', 'adGroups.statistics'])->findOrFail($campaignId);
    }

    public function updateCampaign(int $campaignId, UpdateCampaignDTO $data): VkAdsCampaign
    {
        $campaign = VkAdsCampaign::findOrFail($campaignId);

        // Обновляем в VK Ads
        $this->apiService->makeAuthenticatedRequest($campaign->account, 'campaigns.update', [
            'campaign_id' => $campaign->vk_campaign_id,
            'name' => $data->name ?? $campaign->name,
            'daily_budget' => $data->dailyBudget ? $data->dailyBudget * 100 : $campaign->daily_budget * 100,
        ]);

        // Обновляем в БД
        $campaign->update($data->toArray());

        return $campaign;
    }

    public function deleteCampaign(int $campaignId): bool
    {
        $campaign = VkAdsCampaign::findOrFail($campaignId);

        // Удаляем в VK Ads
        $this->apiService->makeAuthenticatedRequest($campaign->account, 'campaigns.delete', [
            'campaign_id' => $campaign->vk_campaign_id,
        ]);

        return $campaign->delete();
    }

    // === УПРАВЛЕНИЕ СТАТУСОМ ===

    public function pauseCampaign(int $campaignId): VkAdsCampaign
    {
        $campaign = VkAdsCampaign::findOrFail($campaignId);

        $this->apiService->makeAuthenticatedRequest($campaign->account, 'campaigns.update', [
            'campaign_id' => $campaign->vk_campaign_id,
            'status' => 'paused',
        ]);

        $campaign->update(['status' => 'paused']);

        return $campaign;
    }

    public function resumeCampaign(int $campaignId): VkAdsCampaign
    {
        $campaign = VkAdsCampaign::findOrFail($campaignId);

        $this->apiService->makeAuthenticatedRequest($campaign->account, 'campaigns.update', [
            'campaign_id' => $campaign->vk_campaign_id,
            'status' => 'active',
        ]);

        $campaign->update(['status' => 'active']);

        return $campaign;
    }

    public function archiveCampaign(int $campaignId): VkAdsCampaign
    {
        $campaign = VkAdsCampaign::findOrFail($campaignId);
        $campaign->update(['status' => 'archived']);

        return $campaign;
    }

    // === СИНХРОНИЗАЦИЯ ===

    public function syncCampaignFromVk(int $vkCampaignId): VkAdsCampaign
    {
        $campaign = VkAdsCampaign::where('vk_campaign_id', $vkCampaignId)->firstOrFail();

        $vkData = $this->apiService->makeAuthenticatedRequest($campaign->account, 'campaigns.get', [
            'campaign_ids' => [$vkCampaignId],
        ]);

        if (! empty($vkData)) {
            $campaign->update([
                'name' => $vkData[0]['name'],
                'status' => $vkData[0]['status'],
                'daily_budget' => $vkData[0]['daily_budget'] / 100,
                'vk_data' => $vkData[0],
                'last_sync_at' => now(),
            ]);
        }

        return $campaign;
    }

    public function syncAllCampaigns(VkAdsAccount $account): Collection
    {
        $campaigns = $account->campaigns;

        foreach ($campaigns as $campaign) {
            try {
                $this->syncCampaignFromVk($campaign->vk_campaign_id);
            } catch (\Exception $e) {
                \Log::error("Failed to sync campaign {$campaign->id}: ".$e->getMessage());
            }
        }

        return $campaigns;
    }

    // === КОПИРОВАНИЕ И ШАБЛОНЫ ===

    public function copyCampaign(int $campaignId, array $modifications = []): VkAdsCampaign
    {
        $originalCampaign = VkAdsCampaign::findOrFail($campaignId);

        $newCampaignData = array_merge($originalCampaign->toArray(), $modifications, [
            'id' => null,
            'uuid' => null,
            'vk_campaign_id' => null,
            'name' => ($modifications['name'] ?? $originalCampaign->name).' (Copy)',
            'status' => 'paused',
        ]);

        return $this->createCampaign($originalCampaign->account, CreateCampaignDTO::fromArray($newCampaignData));
    }

    // === БЮДЖЕТ ===

    public function updateBudget(int $campaignId, BudgetUpdateDTO $budget): VkAdsCampaign
    {
        $campaign = VkAdsCampaign::findOrFail($campaignId);

        $this->apiService->makeAuthenticatedRequest($campaign->account, 'campaigns.update', [
            'campaign_id' => $campaign->vk_campaign_id,
            'daily_budget' => $budget->dailyBudget ? $budget->dailyBudget * 100 : null,
            'total_budget' => $budget->totalBudget ? $budget->totalBudget * 100 : null,
        ]);

        $campaign->update([
            'daily_budget' => $budget->dailyBudget,
            'total_budget' => $budget->totalBudget,
            'budget_type' => $budget->budgetType,
        ]);

        return $campaign;
    }

    public function getBudgetRecommendations(int $campaignId): array
    {
        $campaign = VkAdsCampaign::findOrFail($campaignId);

        return $this->apiService->makeAuthenticatedRequest($campaign->account, 'campaigns.getBudgetRecommendations', [
            'campaign_id' => $campaign->vk_campaign_id,
        ]);
    }
}
