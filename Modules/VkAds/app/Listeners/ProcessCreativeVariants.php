<?php

namespace Modules\VkAds\app\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Modules\VkAds\app\Events\CreativeUploaded;
use Modules\VkAds\app\Services\VkAdsCreativeService;

class ProcessCreativeVariants implements ShouldQueue
{
    public function __construct(
        private VkAdsCreativeService $creativeService
    ) {}

    public function handle(CreativeUploaded $event): void
    {
        try {
            $creative = $event->creative;

            // Если включена автогенерация вариантов
            if (config('vkads.creatives.auto_generate_variants', true)) {
                $this->generateMissingVariants($creative);
            }

            // Обновляем кэш креативов
            $this->updateCreativeCache($creative);

        } catch (\Exception $e) {
            Log::error('Failed to process creative variants: '.$e->getMessage(), [
                'creative_id' => $event->creative->id,
                'exception' => $e->getTraceAsString(),
            ]);
        }
    }

    private function generateMissingVariants($creative): void
    {
        // Логика автогенерации недостающих вариантов
        // Например, если есть видео 16:9, можем сгенерировать 1:1 и 9:16

        if ($creative->isVideo() && $creative->hasVariantForAspectRatio('16:9')) {
            $requiredRatios = ['1:1', '9:16'];

            foreach ($requiredRatios as $ratio) {
                if (! $creative->hasVariantForAspectRatio($ratio)) {
                    // Здесь можно запустить Job для генерации варианта
                    \Modules\VkAds\app\Jobs\GenerateCreativeVariant::dispatch($creative, $ratio);
                }
            }
        }
    }

    private function updateCreativeCache($creative): void
    {
        $cacheKey = "vk_ads_creative_{$creative->id}";
        \Cache::forget($cacheKey);

        // Сбрасываем кэш списка креативов для аккаунта
        \Cache::tags(['vk-ads-creatives'])->flush();
    }
}
