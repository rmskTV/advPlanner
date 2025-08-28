<?php

namespace Modules\VkAds\app\Services;

use Modules\VkAds\app\DTOs\CreateInstreamAdDTO;
use Modules\VkAds\app\Models\VkAdsAd;
use Modules\VkAds\app\Models\VkAdsAdGroup;
use Modules\VkAds\app\Models\VkAdsCreative;

class VkAdsAdService
{
    private VkAdsApiService $apiService;

    private VkAdsCreativeService $creativeService;

    public function __construct(VkAdsApiService $apiService, VkAdsCreativeService $creativeService)
    {
        $this->apiService = $apiService;
        $this->creativeService = $creativeService;
    }

    // === СОЗДАНИЕ INSTREAM ОБЪЯВЛЕНИЙ ===

    public function createInstreamAdWithVariants(VkAdsAdGroup $adGroup, CreateInstreamAdDTO $data): VkAdsAd
    {
        $creative = VkAdsCreative::findOrFail($data->creativeId);

        // Валидируем, что креатив подходит для instream
        if (! $creative->isInstream() || ! $creative->isVideo()) {
            throw new \Exception('Creative must be video type and instream format');
        }

        // Проверяем наличие обязательных форматов для instream
        if (! $creative->hasVariantForAspectRatio('16:9')) {
            throw new \Exception('Instream ad requires 16:9 video variant');
        }

        // Подготавливаем данные для VK API с учетом всех вариантов
        $creativeVariants = [];

        // Основной креатив (16:9)
        $primary169 = $creative->getVariantForAspectRatio('16:9');
        if ($primary169) {
            $creativeVariants['16:9'] = [
                'creative_id' => $creative->vk_creative_id,
                'video_url' => $this->creativeService->getMediaFileForAspectRatio($creative, '16:9')->getPublicUrl(),
            ];
        }

        // Дополнительные варианты для мобильных форматов
        foreach (['9:16', '1:1'] as $ratio) {
            if ($creative->hasVariantForAspectRatio($ratio)) {
                $mediaFile = $this->creativeService->getMediaFileForAspectRatio($creative, $ratio);
                $creativeVariants[$ratio] = [
                    'video_url' => $mediaFile->getPublicUrl(),
                ];
            }
        }

        // Создаем объявление в VK Ads
        $vkResponse = $this->apiService->makeAuthenticatedRequest($adGroup->campaign->account, 'ads.create', [
            'account_id' => $adGroup->campaign->account->vk_account_id,
            'adgroup_id' => $adGroup->vk_ad_group_id,
            'name' => $data->name,
            'headline' => $data->headline,
            'description' => $data->description,
            'final_url' => $data->finalUrl,
            'call_to_action' => $data->callToAction,
            'creative_variants' => $creativeVariants, // НОВОЕ: передаем все варианты
            'instream_settings' => [
                'position' => $data->instreamPosition,
                'skippable' => $data->skippable,
                'skip_offset' => $data->skipOffset,
            ],
        ]);

        // Создаем объявление в БД
        $ad = VkAdsAd::create([
            'vk_ad_id' => $vkResponse['ad_id'],
            'vk_ads_ad_group_id' => $adGroup->id,
            'name' => $data->name,
            'headline' => $data->headline,
            'description' => $data->description,
            'call_to_action' => $data->callToAction,
            'final_url' => $data->finalUrl,
            'is_instream' => true,
            'instream_position' => $data->instreamPosition,
            'skippable' => $data->skippable,
            'skip_offset' => $data->skipOffset,
            'status' => 'active',
            'moderation_status' => 'pending',
            'vk_data' => $vkResponse,
        ]);

        // НОВОЕ: Привязываем креативы с ролями
        $ad->attachCreative($creative, 'primary');

        // Привязываем дополнительные варианты, если есть
        $roleMap = [
            '16:9' => 'variant_16_9',
            '9:16' => 'variant_9_16',
            '1:1' => 'variant_1_1',
            '4:5' => 'variant_4_5',
        ];

        foreach ($roleMap as $aspectRatio => $role) {
            if ($creative->hasVariantForAspectRatio($aspectRatio) && $role !== 'primary') {
                $ad->attachCreative($creative, $role);
            }
        }

        return $ad;
    }

    // === СОЗДАНИЕ УНИВЕРСАЛЬНОГО ОБЪЯВЛЕНИЯ ===

    public function createUniversalAd(VkAdsAdGroup $adGroup, array $creativeIds, CreateAdDTO $data): VkAdsAd
    {
        // Получаем все креативы
        $creatives = VkAdsCreative::whereIn('id', $creativeIds)->get();

        // Группируем креативы по соотношению сторон
        $creativesByRatio = [];
        foreach ($creatives as $creative) {
            $ratio = $creative->width && $creative->height
                ? $creative->width.':'.$creative->height
                : '1:1';
            $creativesByRatio[$ratio] = $creative;
        }

        // Подготавливаем данные для VK API
        $creativeVariants = [];
        foreach ($creativesByRatio as $ratio => $creative) {
            $creativeVariants[$ratio] = [
                'creative_id' => $creative->vk_creative_id,
                'url' => $creative->getPublicUrl(),
            ];
        }

        // Создаем объявление в VK Ads
        $vkResponse = $this->apiService->makeAuthenticatedRequest($adGroup->campaign->account, 'ads.create', [
            'account_id' => $adGroup->campaign->account->vk_account_id,
            'adgroup_id' => $adGroup->vk_ad_group_id,
            'name' => $data->name,
            'headline' => $data->headline,
            'description' => $data->description,
            'final_url' => $data->finalUrl,
            'call_to_action' => $data->callToAction,
            'creative_variants' => $creativeVariants,
        ]);

        // Создаем объявление в БД
        $ad = VkAdsAd::create([
            'vk_ad_id' => $vkResponse['ad_id'],
            'vk_ads_ad_group_id' => $adGroup->id,
            'name' => $data->name,
            'headline' => $data->headline,
            'description' => $data->description,
            'call_to_action' => $data->callToAction,
            'final_url' => $data->finalUrl,
            'status' => 'active',
            'moderation_status' => 'pending',
            'vk_data' => $vkResponse,
        ]);

        // Привязываем все креативы с соответствующими ролями
        $roleMap = [
            '16:9' => 'variant_16_9',
            '9:16' => 'variant_9_16',
            '1:1' => 'variant_1_1',
            '4:5' => 'variant_4_5',
        ];

        $isPrimarySet = false;
        foreach ($creativesByRatio as $ratio => $creative) {
            $role = ! $isPrimarySet ? 'primary' : ($roleMap[$ratio] ?? 'variant_'.str_replace(':', '_', $ratio));
            $ad->attachCreative($creative, $role);
            $isPrimarySet = true;
        }

        return $ad;
    }

    // === РАБОТА С ВАРИАНТАМИ КРЕАТИВОВ ===

    public function addCreativeVariant(VkAdsAd $ad, VkAdsCreative $creative, string $aspectRatio): void
    {
        $roleMap = [
            '16:9' => 'variant_16_9',
            '9:16' => 'variant_9_16',
            '1:1' => 'variant_1_1',
            '4:5' => 'variant_4_5',
        ];

        $role = $roleMap[$aspectRatio] ?? 'variant_'.str_replace(':', '_', $aspectRatio);

        if ($ad->hasCreativeForRole($role)) {
            throw new \Exception("Ad already has creative for aspect ratio {$aspectRatio}");
        }

        $ad->attachCreative($creative, $role);

        // Обновляем объявление в VK Ads
        $this->updateAdCreativesInVk($ad);
    }

    public function removeCreativeVariant(VkAdsAd $ad, string $aspectRatio): void
    {
        $roleMap = [
            '16:9' => 'variant_16_9',
            '9:16' => 'variant_9_16',
            '1:1' => 'variant_1_1',
            '4:5' => 'variant_4_5',
        ];

        $role = $roleMap[$aspectRatio] ?? 'variant_'.str_replace(':', '_', $aspectRatio);

        $creative = $ad->creatives()->wherePivot('role', $role)->first();

        if ($creative) {
            $ad->detachCreative($creative);
            $this->updateAdCreativesInVk($ad);
        }
    }

    private function updateAdCreativesInVk(VkAdsAd $ad): void
    {
        $creativeVariants = [];

        foreach ($ad->getActiveCreatives() as $creative) {
            $role = $creative->pivot->role;
            $aspectRatio = $this->roleToAspectRatio($role);

            $creativeVariants[$aspectRatio] = [
                'creative_id' => $creative->vk_creative_id,
                'url' => $creative->getPublicUrl(),
            ];
        }

        $this->apiService->makeAuthenticatedRequest($ad->adGroup->campaign->account, 'ads.update', [
            'ad_id' => $ad->vk_ad_id,
            'creative_variants' => $creativeVariants,
        ]);
    }

    private function roleToAspectRatio(string $role): string
    {
        $map = [
            'primary' => '16:9', // По умолчанию
            'variant_16_9' => '16:9',
            'variant_9_16' => '9:16',
            'variant_1_1' => '1:1',
            'variant_4_5' => '4:5',
        ];

        return $map[$role] ?? '1:1';
    }
}
