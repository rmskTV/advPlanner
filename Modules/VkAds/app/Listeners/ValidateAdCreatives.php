<?php

namespace Modules\VkAds\app\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Modules\VkAds\app\Events\AdCreated;
use Modules\VkAds\app\Services\VkAdsValidationService;

class ValidateAdCreatives implements ShouldQueue
{
    public function __construct(
        private VkAdsValidationService $validationService
    ) {}

    public function handle(AdCreated $event): void
    {
        try {
            $ad = $event->ad;
            $validationErrors = [];

            // Валидируем все креативы объявления
            foreach ($ad->getActiveCreatives() as $creative) {
                $role = $creative->pivot->role;
                $aspectRatio = $this->roleToAspectRatio($role);

                if ($creative->isVideo()) {
                    $errors = $this->validationService->validateVideoForFormat(
                        $creative->videoFile,
                        $ad->is_instream ? 'instream' : 'banner',
                        $aspectRatio
                    );
                } else {
                    $errors = $this->validationService->validateImageForFormat(
                        $creative->imageFile,
                        'banner',
                        $aspectRatio
                    );
                }

                if (! empty($errors)) {
                    $validationErrors[$role] = $errors;
                }
            }

            // Если есть ошибки валидации, логируем и возможно паузим объявление
            if (! empty($validationErrors)) {
                Log::warning("Ad {$ad->id} has validation errors", [
                    'ad_id' => $ad->id,
                    'ad_name' => $ad->name,
                    'errors' => $validationErrors,
                ]);

                // Можно автоматически поставить на паузу
                if (config('vkads.validation.auto_pause_invalid_ads', false)) {
                    $ad->update(['status' => 'paused']);
                }
            }

        } catch (\Exception $e) {
            Log::error('Failed to validate ad creatives: '.$e->getMessage(), [
                'ad_id' => $event->ad->id,
                'exception' => $e->getTraceAsString(),
            ]);
        }
    }

    private function roleToAspectRatio(string $role): string
    {
        $map = [
            'primary' => '16:9',
            'variant_16_9' => '16:9',
            'variant_9_16' => '9:16',
            'variant_1_1' => '1:1',
            'variant_4_5' => '4:5',
        ];

        return $map[$role] ?? '1:1';
    }
}
