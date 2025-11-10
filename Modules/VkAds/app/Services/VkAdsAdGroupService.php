<?php

namespace Modules\VkAds\app\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Modules\VkAds\app\Models\VkAdsAccount;
use Modules\VkAds\app\Models\VkAdsAdGroup;

class VkAdsAdGroupService
{
    private VkAdsApiService $apiService;

    public function __construct(VkAdsApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    public function getAdGroups(VkAdsAccount $account): Collection
    {
        return VkAdsAdGroup::where('vk_ads_account_id', $account->id)
            ->with(['campaign', 'orderItem.customerOrder', 'ads'])
            ->get();
    }

    public function syncAdGroupsForCampaigns(VkAdsAccount $account, Collection $campaigns): Collection
    {
        try {
            if ($campaigns->isEmpty()) {
                Log::info('No campaigns to sync ad groups for');

                return collect();
            }

            $campaignIds = $campaigns->pluck('vk_campaign_id')->toArray();

            Log::info('Syncing ad groups for campaigns', [
                'account_id' => $account->id,
                'campaign_ids' => $campaignIds,
            ]);

            // ИСПРАВЛЕНО: запрашиваем все нужные поля для групп объявлений
            $vkAdGroups = $this->apiService->makeAuthenticatedRequest($account, 'ad_groups', [
                'ad_plan_id__in' => implode(',', $campaignIds),
                'fields' => 'id,name,status,ad_plan_id,targetings,age_restrictions,autobidding_mode,budget_limit,budget_limit_day,max_price,uniq_shows_limit,uniq_shows_period',
            ]);

            Log::info('Received ad groups from VK', [
                'count' => count($vkAdGroups),
                'sample_fields' => ! empty($vkAdGroups) ? array_keys($vkAdGroups[0]) : [],
            ]);

            foreach ($vkAdGroups as $vkAdGroup) {
                // Ищем кампанию по ad_plan_id из ответа API
                $campaign = $campaigns->firstWhere('vk_campaign_id', $vkAdGroup['ad_plan_id'] ?? null);

                if (! $campaign) {
                    Log::warning('Campaign not found for ad group', [
                        'ad_group_id' => $vkAdGroup['id'],
                        'ad_plan_id' => $vkAdGroup['ad_plan_id'] ?? 'missing',
                    ]);

                    continue;
                }

                $adGroup = VkAdsAdGroup::updateOrCreate([
                    'vk_ad_group_id' => $vkAdGroup['id'],
                ], [
                    'vk_ads_account_id' => $account->id,
                    'vk_ads_campaign_id' => $campaign->id,
                    // 'customer_order_item_id' => null,
                    'name' => $vkAdGroup['name'],
                    'status' => $this->mapVkStatus($vkAdGroup['status'] ?? 'active'),
                    'bid' => $vkAdGroup['bid'] ?? null,
                    'targetings' => $vkAdGroup['targeting'] ?? null,

                    // ДОБАВЛЕНО: новые поля
                    'age_restrictions' => $vkAdGroup['age_restrictions'] ?? null,
                    'autobidding_mode' => $vkAdGroup['autobidding_mode'] ?? null,
                    'budget_limit' => $vkAdGroup['budget_limit'] ?? null,
                    'budget_limit_day' => $vkAdGroup['budget_limit_day'] ?? null,
                    'max_price' => $vkAdGroup['max_price'] ?? null,
                    'uniq_shows_limit' => $vkAdGroup['uniq_shows_limit'] ?? null,
                    'uniq_shows_period' => $vkAdGroup['uniq_shows_period'] ?? null,

                    'last_sync_at' => now(),
                ]);

                Log::info('Synced ad group', [
                    'vk_ad_group_id' => $vkAdGroup['id'],
                    'name' => $vkAdGroup['name'],
                    'campaign_id' => $campaign->id,
                    'budget_limit_day' => $adGroup->budget_limit_day,
                    'max_price' => $adGroup->max_price,
                ]);
            }

        } catch (\Exception $e) {
            Log::warning('Failed to sync ad groups for campaigns: '.$e->getMessage());
        }

        return $this->getAdGroups($account);
    }

    private function mapVkStatus($vkStatus): string
    {
        return match ($vkStatus) {
            1, 'active' => 'active',
            0, 'paused' => 'paused',
            'deleted' => 'deleted',
            default => 'paused'
        };
    }
}
