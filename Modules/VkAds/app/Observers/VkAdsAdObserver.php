<?php

namespace Modules\VkAds\app\Observers;

use Modules\VkAds\app\Models\VkAdsAd;

class VkAdsAdObserver
{
    /**
     * Обработка после создания объявления
     */
    public function created(VkAdsAd $ad): void
    {
        \Log::info("Ad created: {$ad->name}", [
            'ad_id' => $ad->id,
            'vk_ad_id' => $ad->vk_ad_id,
            'ad_group_id' => $ad->vk_ads_ad_group_id,
            'is_instream' => $ad->is_instream,
            'creatives_count' => $ad->creatives()->count(),
        ]);

        // Сбрасываем кэш группы объявлений
        \Cache::tags(['vk-ads-ads'])->flush();
    }

    /**
     * Обработка после обновления объявления
     */
    public function updated(VkAdsAd $ad): void
    {
        // Логируем изменения статуса
        if ($ad->isDirty('status')) {
            \Log::info("Ad status changed: {$ad->name}", [
                'ad_id' => $ad->id,
                'old_status' => $ad->getOriginal('status'),
                'new_status' => $ad->status,
            ]);
        }

        // Логируем изменения модерации
        if ($ad->isDirty('moderation_status')) {
            \Log::info("Ad moderation status changed: {$ad->name}", [
                'ad_id' => $ad->id,
                'old_status' => $ad->getOriginal('moderation_status'),
                'new_status' => $ad->moderation_status,
                'comment' => $ad->moderation_comment,
            ]);
        }
    }

    /**
     * Обработка перед удалением объявления
     */
    public function deleting(VkAdsAd $ad): void
    {
        \Log::info("Ad being deleted: {$ad->name}", [
            'ad_id' => $ad->id,
            'vk_ad_id' => $ad->vk_ad_id,
            'final_status' => $ad->status,
        ]);

        // Отвязываем все креативы
        $ad->creatives()->detach();
    }
}
