<?php

namespace Modules\VkAds\app\Jobs;

use Modules\VkAds\app\Models\VkAdsAdGroup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ValidateAdGroupTargeting implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public VkAdsAdGroup $adGroup
    ) {
        $this->onQueue('vk-ads-validation');
    }

    public function handle(): void
    {
        $targeting = $this->adGroup->targeting ?? [];
        $errors = [];
        $warnings = [];

        // Валидация возрастного диапазона
        if (isset($targeting['age_from']) && isset($targeting['age_to'])) {
            if ($targeting['age_from'] > $targeting['age_to']) {
                $errors[] = 'Некорректный возрастной диапазон';
            }

            $ageRange = $targeting['age_to'] - $targeting['age_from'];
            if ($ageRange < 5) {
                $warnings[] = 'Слишком узкий возрастной диапазон может ограничить охват';
            }
        }

        // Валидация географического таргетинга
        if (isset($targeting['geo']) && !empty($targeting['geo'])) {
            if (count($targeting['geo']) > 100) {
                $warnings[] = 'Слишком много географических регионов';
            }
        }

        // Валидация интересов
        if (isset($targeting['interests']) && !empty($targeting['interests'])) {
            if (count($targeting['interests']) > 50) {
                $warnings[] = 'Слишком много интересов в таргетинге';
            }
        }

        // Логируем результаты валидации
        if (!empty($errors)) {
            \Log::error('Ad Group targeting validation errors', [
                'ad_group_id' => $this->adGroup->id,
                'errors' => $errors
            ]);
        }

        if (!empty($warnings)) {
            \Log::warning('Ad Group targeting validation warnings', [
                'ad_group_id' => $this->adGroup->id,
                'warnings' => $warnings
            ]);
        }

        if (empty($errors) && empty($warnings)) {
            \Log::info('Ad Group targeting validation passed', [
                'ad_group_id' => $this->adGroup->id
            ]);
        }
    }
}
