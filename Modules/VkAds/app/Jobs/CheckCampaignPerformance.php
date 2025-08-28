<?php

namespace Modules\VkAds\app\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\VkAds\app\DTOs\StatisticsRequestDTO;
use Modules\VkAds\app\Events\CampaignPerformanceAlert;
use Modules\VkAds\app\Models\VkAdsCampaign;
use Modules\VkAds\app\Services\VkAdsStatisticsService;

class CheckCampaignPerformance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public VkAdsCampaign $campaign
    ) {
        $this->onQueue('vk-ads-monitoring');
    }

    public function handle(VkAdsStatisticsService $statisticsService): void
    {
        try {
            // Получаем статистику за последние 24 часа
            $request = new StatisticsRequestDTO(
                dateFrom: Carbon::now()->subDay(),
                dateTo: Carbon::now(),
                metrics: ['clicks', 'impressions', 'spend', 'ctr', 'cpc']
            );

            $stats = $statisticsService->getCampaignStatistics($this->campaign->id, $request);

            // Проверяем пороговые значения
            $alerts = $this->checkPerformanceThresholds($stats);

            if (! empty($alerts)) {
                foreach ($alerts as $alert) {
                    CampaignPerformanceAlert::dispatch(
                        $this->campaign,
                        $alert['type'],
                        $alert['metrics'],
                        $alert['thresholds']
                    );
                }
            }

        } catch (\Exception $e) {
            \Log::error('Failed to check campaign performance: '.$e->getMessage(), [
                'campaign_id' => $this->campaign->id,
                'exception' => $e->getTraceAsString(),
            ]);
        }
    }

    private function checkPerformanceThresholds($stats): array
    {
        $alerts = [];
        $thresholds = config('vkads.performance_thresholds', [
            'min_ctr' => 0.5, // Минимальный CTR в %
            'max_cpc' => 50,   // Максимальный CPC в рублях
            'min_impressions_per_day' => 1000,
        ]);

        // Проверяем CTR
        if ($stats->getAverageCTR() < $thresholds['min_ctr']) {
            $alerts[] = [
                'type' => 'low_ctr',
                'metrics' => ['ctr' => $stats->getAverageCTR()],
                'thresholds' => ['min_ctr' => $thresholds['min_ctr']],
            ];
        }

        // Проверяем CPC
        if ($stats->getAverageCPC() > $thresholds['max_cpc']) {
            $alerts[] = [
                'type' => 'high_cpc',
                'metrics' => ['cpc' => $stats->getAverageCPC()],
                'thresholds' => ['max_cpc' => $thresholds['max_cpc']],
            ];
        }

        // Проверяем количество показов
        if ($stats->getTotalImpressions() < $thresholds['min_impressions_per_day']) {
            $alerts[] = [
                'type' => 'low_impressions',
                'metrics' => ['impressions' => $stats->getTotalImpressions()],
                'thresholds' => ['min_impressions_per_day' => $thresholds['min_impressions_per_day']],
            ];
        }

        return $alerts;
    }
}
