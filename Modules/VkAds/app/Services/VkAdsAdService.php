<?php

namespace Modules\VkAds\app\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Modules\VkAds\app\Models\VkAdsAccount;
use Modules\VkAds\app\Models\VkAdsAd;

class VkAdsAdService
{
    private VkAdsApiService $apiService;

    public function __construct(VkAdsApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    /**
     * Получить все объявления для аккаунта
     */
    public function getAds(VkAdsAccount $account): Collection
    {
        return VkAdsAd::whereHas('adGroup', function ($query) use ($account) {
            $query->where('vk_ads_account_id', $account->id);
        })->with(['adGroup.campaign'])->get();
    }

    /**
     * Синхронизировать объявления для групп объявлений
     */
    public function syncAdsForAdGroups(VkAdsAccount $account, Collection $adGroups): Collection
    {
        try {
            if ($adGroups->isEmpty()) {
                Log::info('No ad groups to sync ads for');

                // ИСПРАВЛЕНО: возвращаем пустую Eloquent Collection
                return new Collection;
            }

            $adGroupIds = $adGroups->pluck('vk_ad_group_id')->toArray();

            Log::info('Syncing ads for ad groups', [
                'account_id' => $account->id,
                'ad_group_ids' => $adGroupIds,
            ]);

            // Запрашиваем объявления (banners) согласно VK Ads API
            $vkAds = $this->apiService->makeAuthenticatedRequest($account, 'banners', [
                'ad_group_id__in' => implode(',', $adGroupIds),
                'fields' => 'id,name,status,ad_group_id,content,delivery,issues,moderation_status,moderation_reasons,textblocks,urls,ord_marker,created,updated',
            ]);

            Log::info('Received ads from VK', [
                'count' => count($vkAds),
                'sample_fields' => ! empty($vkAds) ? array_keys($vkAds[0]) : [],
            ]);

            // ИСПРАВЛЕНО: используем массив для сбора объектов, затем создаем Eloquent Collection
            $syncedAds = [];

            foreach ($vkAds as $vkAd) {
                try {
                    // Ищем группу объявлений
                    $adGroup = $adGroups->firstWhere('vk_ad_group_id', $vkAd['ad_group_id'] ?? null);

                    if (! $adGroup) {
                        Log::warning('Ad group not found for ad', [
                            'ad_id' => $vkAd['id'],
                            'ad_group_id' => $vkAd['ad_group_id'] ?? 'missing',
                        ]);

                        continue;
                    }

                    $ad = VkAdsAd::updateOrCreate([
                        'vk_ad_id' => $vkAd['id'],
                    ], [
                        'vk_ads_ad_group_id' => $adGroup->id,
                        'name' => $vkAd['name'],
                        'status' => $this->mapVkStatus($vkAd['status'] ?? 'active'),
                        'content' => $vkAd['content'] ?? null,
                        'delivery' => $this->mapDeliveryStatus($vkAd['delivery'] ?? 'pending'),
                        'issues' => $vkAd['issues'] ?? null,
                        'moderation_status' => $this->mapModerationStatus($vkAd['moderation_status'] ?? 'pending'),
                        'moderation_reasons' => $vkAd['moderation_reasons'] ?? null,
                        'textblocks' => $vkAd['textblocks'] ?? null,
                        'urls' => $vkAd['urls'] ?? null,
                        'ord_marker' => $vkAd['ord_marker'] ?? null,
                        'created_at_vk' => $this->parseVkDateTime($vkAd['created'] ?? null),
                        'updated_at_vk' => $this->parseVkDateTime($vkAd['updated'] ?? null),
                        'vk_data' => $vkAd,
                        'last_sync_at' => now(),
                    ]);

                    // ИСПРАВЛЕНО: добавляем в массив
                    $syncedAds[] = $ad;

                    Log::info('Synced ad', [
                        'vk_ad_id' => $vkAd['id'],
                        'name' => $vkAd['name'],
                        'ad_group_id' => $adGroup->id,
                        'status' => $ad->status,
                        'delivery' => $ad->delivery,
                        'moderation_status' => $ad->moderation_status,
                    ]);

                } catch (\Exception $e) {
                    Log::warning("Failed to sync ad {$vkAd['id']}: ".$e->getMessage());
                }
            }

            // ИСПРАВЛЕНО: создаем Eloquent Collection из массива
            $result = new Collection($syncedAds);

            Log::info('Successfully synced ads', ['count' => $result->count()]);

            return $result;

        } catch (\Exception $e) {
            Log::warning('Failed to sync ads for ad groups: '.$e->getMessage());

            // ИСПРАВЛЕНО: возвращаем пустую Eloquent Collection
            return new Collection;
        }
    }

    /**
     * Маппинг статуса VK в наш формат
     */
    private function mapVkStatus($vkStatus): string
    {
        return match ($vkStatus) {
            'active' => VkAdsAd::STATUS_ACTIVE,
            'deleted' => VkAdsAd::STATUS_DELETED,
            'blocked' => VkAdsAd::STATUS_BLOCKED,
            default => VkAdsAd::STATUS_ACTIVE
        };
    }

    /**
     * Маппинг статуса трансляции
     */
    private function mapDeliveryStatus($vkDelivery): string
    {
        return match ($vkDelivery) {
            'pending' => VkAdsAd::DELIVERY_PENDING,
            'delivering' => VkAdsAd::DELIVERY_DELIVERING,
            'not_delivering' => VkAdsAd::DELIVERY_NOT_DELIVERING,
            default => VkAdsAd::DELIVERY_PENDING
        };
    }

    /**
     * Маппинг статуса модерации
     */
    private function mapModerationStatus($vkStatus): string
    {
        return match ($vkStatus) {
            'pending' => VkAdsAd::MODERATION_PENDING,
            'allowed' => VkAdsAd::MODERATION_ALLOWED,
            'banned' => VkAdsAd::MODERATION_BANNED,
            default => VkAdsAd::MODERATION_PENDING
        };
    }

    /**
     * Парсинг даты/времени VK
     */
    private function parseVkDateTime($vkDateTime): ?\Carbon\Carbon
    {
        if (! $vkDateTime) {
            return null;
        }

        try {
            if (is_numeric($vkDateTime)) {
                return \Carbon\Carbon::createFromTimestamp($vkDateTime);
            }

            return \Carbon\Carbon::parse($vkDateTime);
        } catch (\Exception $e) {
            Log::warning("Failed to parse VK datetime: {$vkDateTime}");

            return null;
        }
    }
}
