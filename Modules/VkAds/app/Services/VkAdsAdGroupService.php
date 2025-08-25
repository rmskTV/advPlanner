<?php

namespace Modules\VkAds\app\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Accounting\app\Models\CustomerOrderItem;
use Modules\VkAds\app\DTOs\CreateAdGroupDTO;
use Modules\VkAds\app\Models\VkAdsAdGroup;
use Modules\VkAds\app\Models\VkAdsCampaign;

class VkAdsAdGroupService
{
    private VkAdsApiService $apiService;

    public function __construct(VkAdsApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    /**
     * Создать группу объявлений из строки заказа клиента
     */
    public function createAdGroupFromOrderItem(VkAdsCampaign $campaign, CustomerOrderItem $orderItem, array $adGroupData): VkAdsAdGroup
    {
        // Создаем группу в VK Ads
        $vkResponse = $this->apiService->makeAuthenticatedRequest($campaign->account, 'adgroups.create', [
            'account_id' => $campaign->account->vk_account_id,
            'campaign_id' => $campaign->vk_campaign_id,
            'name' => $adGroupData['name'] ?? $orderItem->product_name,
            'bid' => $adGroupData['bid'] ?? null,
            'targeting' => $adGroupData['targeting'] ?? [],
        ]);

        return VkAdsAdGroup::create([
            'vk_ad_group_id' => $vkResponse['id'],
            'vk_ads_campaign_id' => $campaign->id,
            'customer_order_item_id' => $orderItem->id,
            'name' => $adGroupData['name'] ?? $orderItem->product_name,
            'status' => 'active',
            'bid' => $adGroupData['bid'] ?? null,
            'targeting' => $adGroupData['targeting'] ?? [],
            'placements' => $adGroupData['placements'] ?? [],
            'vk_data' => $vkResponse,
        ]);
    }

    public function createAdGroup(int $campaignId, CreateAdGroupDTO $data): VkAdsAdGroup
    {
        $campaign = VkAdsCampaign::findOrFail($campaignId);

        $vkResponse = $this->apiService->makeAuthenticatedRequest($campaign->account, 'adgroups.create', [
            'account_id' => $campaign->account->vk_account_id,
            'campaign_id' => $campaign->vk_campaign_id,
            'name' => $data->name,
            'bid' => $data->bid ? $data->bid * 100 : null,
            'targeting' => $data->targeting,
        ]);

        return VkAdsAdGroup::create([
            'vk_ad_group_id' => $vkResponse['id'],
            'vk_ads_campaign_id' => $campaign->id,
            'customer_order_item_id' => $data->customerOrderItemId,
            'name' => $data->name,
            'status' => 'active',
            'bid' => $data->bid,
            'targeting' => $data->targeting,
            'placements' => $data->placements,
            'vk_data' => $vkResponse,
        ]);
    }

    public function getAdGroups(int $campaignId): Collection
    {
        return VkAdsAdGroup::where('vk_ads_campaign_id', $campaignId)
            ->with(['orderItem.customerOrder', 'statistics'])
            ->get();
    }

    public function updateAdGroup(int $adGroupId, array $data): VkAdsAdGroup
    {
        $adGroup = VkAdsAdGroup::findOrFail($adGroupId);

        $this->apiService->makeAuthenticatedRequest($adGroup->campaign->account, 'adgroups.update', [
            'adgroup_id' => $adGroup->vk_ad_group_id,
            'name' => $data['name'] ?? $adGroup->name,
            'bid' => isset($data['bid']) ? $data['bid'] * 100 : $adGroup->bid * 100,
            'targeting' => $data['targeting'] ?? $adGroup->targeting,
        ]);

        $adGroup->update($data);

        return $adGroup;
    }

    public function deleteAdGroup(int $adGroupId): bool
    {
        $adGroup = VkAdsAdGroup::findOrFail($adGroupId);

        $this->apiService->makeAuthenticatedRequest($adGroup->campaign->account, 'adgroups.delete', [
            'adgroup_id' => $adGroup->vk_ad_group_id,
        ]);

        return $adGroup->delete();
    }

    /**
     * Получить группы объявлений с полной информацией об учете
     */
    public function getAdGroupsWithAccounting(array $filters = []): Collection
    {
        return VkAdsAdGroup::with([
            'campaign.account.organization',
            'campaign.account.contract.counterparty',
            'orderItem.customerOrder',
            'product',
            'statistics',
        ])->when($filters, function ($query, $filters) {
            // Применяем фильтры
        })->get();
    }
}
