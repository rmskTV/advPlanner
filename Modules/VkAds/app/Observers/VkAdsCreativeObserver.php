<?php

namespace Modules\VkAds\app\Observers;

use Modules\VkAds\app\Models\VkAdsCreative;

class VkAdsCreativeObserver
{
    /**
     * Обработка после создания креатива
     */
    public function created(VkAdsCreative $creative): void
    {
        \Log::info("Creative created: {$creative->name}", [
            'creative_id' => $creative->id,
            'vk_creative_id' => $creative->vk_creative_id,
            'type' => $creative->creative_type,
            'format' => $creative->format,
        ]);

        // Сбрасываем кэш креативов
        \Cache::tags(['vk-ads-creatives'])->flush();
    }

    /**
     * Обработка после обновления креатива
     */
    public function updated(VkAdsCreative $creative): void
    {
        // Логируем важные изменения
        if ($creative->isDirty('moderation_status')) {
            \Log::info("Creative moderation status changed: {$creative->name}", [
                'creative_id' => $creative->id,
                'old_status' => $creative->getOriginal('moderation_status'),
                'new_status' => $creative->moderation_status,
                'comment' => $creative->moderation_comment,
            ]);
        }

        // Сбрасываем кэш
        $cacheKey = "vk_ads_creative_{$creative->id}";
        \Cache::forget($cacheKey);
    }

    /**
     * Обработка перед удалением креатива
     */
    public function deleting(VkAdsCreative $creative): void
    {
        // Проверяем, не используется ли креатив в активных объявлениях
        $activeAdsCount = $creative->ads()->whereIn('status', ['active', 'paused'])->count();

        if ($activeAdsCount > 0) {
            throw new \Exception("Cannot delete creative that is used in {$activeAdsCount} active ads");
        }

        \Log::info("Creative being deleted: {$creative->name}", [
            'creative_id' => $creative->id,
            'vk_creative_id' => $creative->vk_creative_id,
        ]);
    }
}
