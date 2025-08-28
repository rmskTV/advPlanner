<?php

namespace Modules\VkAds\app\Observers;

use Modules\VkAds\app\Models\VkAdsStatistics;
use Modules\VkAds\app\Events\BudgetExhausted;
use Modules\VkAds\app\Events\CampaignPerformanceAlert;
use Modules\VkAds\app\Events\StatisticsUpdated;
use Modules\VkAds\app\Jobs\CheckBudgetLimits;
use Modules\VkAds\app\Jobs\CalculateKPIs;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class VkAdsStatisticsObserver
{
    /**
     * Обработка после создания статистики
     */
    public function created(VkAdsStatistics $statistics): void
    {
        Log::info('VK Ads Statistics created', [
            'statistics_id' => $statistics->id,
            'ad_group_id' => $statistics->vk_ads_ad_group_id,
            'stats_date' => $statistics->stats_date->format('Y-m-d'),
            'period_type' => $statistics->period_type,
            'impressions' => $statistics->impressions,
            'clicks' => $statistics->clicks,
            'spend' => $statistics->spend,
            'ctr' => $statistics->ctr,
            'cpc' => $statistics->cpc,
            'cpm' => $statistics->cpm
        ]);

        // Проверяем бюджетные лимиты после добавления новой статистики
        $this->checkBudgetAfterStatistics($statistics);

        // Проверяем производительность
        $this->checkPerformanceMetrics($statistics);

        // Обновляем агрегированные метрики
        $this->updateAggregatedMetrics($statistics);

        // Сбрасываем связанные кэши
        $this->invalidateStatisticsCache($statistics);

        // Логируем для аудита
        $this->logStatisticsAction($statistics, 'created');
    }

    /**
     * Обработка после обновления статистики
     */
    public function updated(VkAdsStatistics $statistics): void
    {
        $changes = $statistics->getChanges();
        $originalValues = $statistics->getOriginal();

        // Логируем значительные изменения в тратах
        if ($statistics->isDirty('spend')) {
            $spendDifference = $statistics->spend - ($originalValues['spend'] ?? 0);

            Log::info('Statistics spend updated', [
                'statistics_id' => $statistics->id,
                'ad_group_id' => $statistics->vk_ads_ad_group_id,
                'stats_date' => $statistics->stats_date->format('Y-m-d'),
                'old_spend' => $originalValues['spend'],
                'new_spend' => $statistics->spend,
                'difference' => $spendDifference
            ]);

            // Если траты значительно изменились, проверяем бюджет
            if (abs($spendDifference) > 100) { // Изменение больше 100 рублей
                $this->checkBudgetAfterStatistics($statistics);
            }
        }

        // Логируем изменения ключевых метрик
        if ($statistics->isDirty(['impressions', 'clicks', 'ctr', 'cpc', 'cpm'])) {
            $this->logMetricsChange($statistics, $originalValues);
        }

        // Обрабатываем привязку к реализации
        if ($statistics->isDirty('sale_item_id')) {
            $this->handleSaleItemConnection($statistics, $originalValues['sale_item_id'], $statistics->sale_item_id);
        }

        // Пересчитываем KPI после обновления
        $this->recalculateKPIs($statistics);

        // Обновляем агрегированные метрики
        $this->updateAggregatedMetrics($statistics);

        // Сбрасываем кэши
        $this->invalidateStatisticsCache($statistics);

        // Генерируем событие обновления статистики
        if (!empty($changes)) {
            if (class_exists(StatisticsUpdated::class)) {
                StatisticsUpdated::dispatch($statistics, $originalValues);
            }

            // Логируем для аудита
            $this->logStatisticsAction($statistics, 'updated', $changes);
        }
    }

    /**
     * Обработка перед удалением статистики
     */
    public function deleting(VkAdsStatistics $statistics): void
    {
        Log::warning('VK Ads Statistics being deleted', [
            'statistics_id' => $statistics->id,
            'ad_group_id' => $statistics->vk_ads_ad_group_id,
            'stats_date' => $statistics->stats_date->format('Y-m-d'),
            'spend' => $statistics->spend,
            'sale_item_id' => $statistics->sale_item_id
        ]);

        // Предупреждаем, если удаляем статистику, привязанную к реализации
        if ($statistics->sale_item_id) {
            Log::warning('Deleting statistics linked to sale item', [
                'statistics_id' => $statistics->id,
                'sale_item_id' => $statistics->sale_item_id
            ]);
        }

        // Логируем для аудита
        $this->logStatisticsAction($statistics, 'deleting');
    }

    /**
     * Обработка после удаления статистики
     */
    public function deleted(VkAdsStatistics $statistics): void
    {
        Log::info('VK Ads Statistics deleted', [
            'statistics_id' => $statistics->id,
            'ad_group_id' => $statistics->vk_ads_ad_group_id,
            'stats_date' => $statistics->stats_date->format('Y-m-d')
        ]);

        // Пересчитываем агрегированные метрики после удаления
        $this->recalculateAggregatedMetricsForAdGroup($statistics->vk_ads_ad_group_id);

        // Сбрасываем кэши
        $this->invalidateStatisticsCache($statistics);

        // Логируем для аудита
        $this->logStatisticsAction($statistics, 'deleted');
    }

    /**
     * Обработка восстановления статистики
     */
    public function restored(VkAdsStatistics $statistics): void
    {
        Log::info('VK Ads Statistics restored', [
            'statistics_id' => $statistics->id,
            'ad_group_id' => $statistics->vk_ads_ad_group_id,
            'stats_date' => $statistics->stats_date->format('Y-m-d')
        ]);

        // Пересчитываем метрики после восстановления
        $this->updateAggregatedMetrics($statistics);

        // Сбрасываем кэши
        $this->invalidateStatisticsCache($statistics);

        // Логируем для аудита
        $this->logStatisticsAction($statistics, 'restored');
    }

    // === ПРИВАТНЫЕ МЕТОДЫ ===

    /**
     * Проверка бюджетных лимитов после обновления статистики
     */
    private function checkBudgetAfterStatistics(VkAdsStatistics $statistics): void
    {
        $adGroup = $statistics->adGroup;
        $campaign = $adGroup->campaign;

        // Проверяем дневной бюджет
        if ($campaign->daily_budget) {
            $todaySpend = $this->getTodaySpendForCampaign($campaign);
            $budgetUsagePercent = ($todaySpend / $campaign->daily_budget) * 100;

            if ($budgetUsagePercent >= 90) { // 90% от дневного бюджета
                Log::warning('Daily budget nearly exhausted', [
                    'campaign_id' => $campaign->id,
                    'today_spend' => $todaySpend,
                    'daily_budget' => $campaign->daily_budget,
                    'usage_percent' => round($budgetUsagePercent, 2)
                ]);

                if ($budgetUsagePercent >= 100) {
                    BudgetExhausted::dispatch($campaign, $todaySpend, $campaign->daily_budget);
                }
            }
        }

        // Проверяем общий бюджет
        if ($campaign->total_budget) {
            $totalSpend = $campaign->getTotalSpend();
            $budgetUsagePercent = ($totalSpend / $campaign->total_budget) * 100;

            if ($budgetUsagePercent >= 95) { // 95% от общего бюджета
                BudgetExhausted::dispatch($campaign, $totalSpend, $campaign->total_budget);
            }
        }
    }

    /**
     * Проверка метрик производительности
     */
    private function checkPerformanceMetrics(VkAdsStatistics $statistics): void
    {
        $thresholds = config('vkads.performance_thresholds', [
            'min_ctr' => 0.5,
            'max_cpc' => 50,
            'min_impressions_per_day' => 100
        ]);

        $alerts = [];

        // Проверяем CTR
        if ($statistics->ctr > 0 && $statistics->ctr < $thresholds['min_ctr']) {
            $alerts[] = [
                'type' => 'low_ctr',
                'metric' => 'ctr',
                'value' => $statistics->ctr,
                'threshold' => $thresholds['min_ctr']
            ];
        }

        // Проверяем CPC
        if ($statistics->cpc > $thresholds['max_cpc']) {
            $alerts[] = [
                'type' => 'high_cpc',
                'metric' => 'cpc',
                'value' => $statistics->cpc,
                'threshold' => $thresholds['max_cpc']
            ];
        }

        // Проверяем количество показов
        if ($statistics->impressions < $thresholds['min_impressions_per_day']) {
            $alerts[] = [
                'type' => 'low_impressions',
                'metric' => 'impressions',
                'value' => $statistics->impressions,
                'threshold' => $thresholds['min_impressions_per_day']
            ];
        }

        // Генерируем алерты производительности
        foreach ($alerts as $alert) {
            Log::warning("Performance alert: {$alert['type']}", [
                'ad_group_id' => $statistics->vk_ads_ad_group_id,
                'stats_date' => $statistics->stats_date->format('Y-m-d'),
                'metric' => $alert['metric'],
                'value' => $alert['value'],
                'threshold' => $alert['threshold']
            ]);

            if (class_exists(CampaignPerformanceAlert::class)) {
                CampaignPerformanceAlert::dispatch(
                    $statistics->adGroup->campaign,
                    $alert['type'],
                    [$alert['metric'] => $alert['value']],
                    [$alert['metric'] => $alert['threshold']]
                );
            }
        }
    }

    /**
     * Логирование изменений метрик
     */
    private function logMetricsChange(VkAdsStatistics $statistics, array $originalValues): void
    {
        $metricsChanges = [];
        $significantChanges = [];

        $metrics = ['impressions', 'clicks', 'spend', 'ctr', 'cpc', 'cpm'];

        foreach ($metrics as $metric) {
            if ($statistics->isDirty($metric)) {
                $oldValue = $originalValues[$metric] ?? 0;
                $newValue = $statistics->$metric;
                $change = $newValue - $oldValue;
                $changePercent = $oldValue > 0 ? ($change / $oldValue) * 100 : 0;

                $metricsChanges[$metric] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                    'change' => $change,
                    'change_percent' => round($changePercent, 2)
                ];

                // Считаем значительными изменения больше 20%
                if (abs($changePercent) > 20) {
                    $significantChanges[] = $metric;
                }
            }
        }

        if (!empty($metricsChanges)) {
            $logLevel = !empty($significantChanges) ? 'warning' : 'info';

            Log::$logLevel('Statistics metrics changed', [
                'statistics_id' => $statistics->id,
                'ad_group_id' => $statistics->vk_ads_ad_group_id,
                'stats_date' => $statistics->stats_date->format('Y-m-d'),
                'metrics_changes' => $metricsChanges,
                'significant_changes' => $significantChanges
            ]);
        }
    }

    /**
     * Обработка привязки к строке реализации
     */
    private function handleSaleItemConnection(VkAdsStatistics $statistics, ?int $oldSaleItemId, ?int $newSaleItemId): void
    {
        // Если привязали к строке реализации
        if (!$oldSaleItemId && $newSaleItemId) {
            $saleItem = \Modules\Accounting\app\Models\SaleItem::find($newSaleItemId);

            Log::info('Statistics linked to sale item', [
                'statistics_id' => $statistics->id,
                'sale_item_id' => $newSaleItemId,
                'sale_id' => $saleItem?->sale_id,
                'product_name' => $saleItem?->product_name,
                'amount' => $saleItem?->amount,
                'stats_spend' => $statistics->spend
            ]);

            // Проверяем соответствие трат статистики и суммы в реализации
            if ($saleItem && abs($statistics->spend - $saleItem->amount) > 1) {
                Log::warning('Spend mismatch between statistics and sale item', [
                    'statistics_id' => $statistics->id,
                    'stats_spend' => $statistics->spend,
                    'sale_amount' => $saleItem->amount,
                    'difference' => abs($statistics->spend - $saleItem->amount)
                ]);
            }
        }

        // Если отвязали от строки реализации
        if ($oldSaleItemId && !$newSaleItemId) {
            Log::warning('Statistics unlinked from sale item', [
                'statistics_id' => $statistics->id,
                'old_sale_item_id' => $oldSaleItemId
            ]);
        }

        // Если изменили привязку
        if ($oldSaleItemId && $newSaleItemId && $oldSaleItemId !== $newSaleItemId) {
            Log::info('Statistics sale item changed', [
                'statistics_id' => $statistics->id,
                'old_sale_item_id' => $oldSaleItemId,
                'new_sale_item_id' => $newSaleItemId
            ]);
        }
    }

    /**
     * Пересчет KPI после изменения статистики
     */
    private function recalculateKPIs(VkAdsStatistics $statistics): void
    {
        // Пересчитываем CTR
        if ($statistics->impressions > 0) {
            $newCTR = ($statistics->clicks / $statistics->impressions) * 100;

            if (abs($newCTR - $statistics->ctr) > 0.01) { // Если разница больше 0.01%
                $statistics->update(['ctr' => round($newCTR, 4)]);
            }
        }

        // Пересчитываем CPC
        if ($statistics->clicks > 0) {
            $newCPC = $statistics->spend / $statistics->clicks;

            if (abs($newCPC - $statistics->cpc) > 0.01) {
                $statistics->update(['cpc' => round($newCPC, 2)]);
            }
        }

        // Пересчитываем CPM
        if ($statistics->impressions > 0) {
            $newCPM = ($statistics->spend / $statistics->impressions) * 1000;

            if (abs($newCPM - $statistics->cpm) > 0.01) {
                $statistics->update(['cpm' => round($newCPM, 2)]);
            }
        }
    }

    /**
     * Обновление агрегированных метрик
     */
    private function updateAggregatedMetrics(VkAdsStatistics $statistics): void
    {
        try {
            $adGroup = $statistics->adGroup;
            $campaign = $adGroup->campaign;
            $account = $campaign->account;

            // Обновляем метрики группы объявлений
            $this->updateAdGroupMetrics($adGroup);

            // Обновляем метрики кампании
            $this->updateCampaignMetrics($campaign);

            // Обновляем метрики аккаунта
            $this->updateAccountMetrics($account);

            // Планируем пересчет глобальных метрик
            CalculateKPIs::dispatch($account)->delay(now()->addMinutes(5));

        } catch (\Exception $e) {
            Log::error('Failed to update aggregated metrics: ' . $e->getMessage(), [
                'statistics_id' => $statistics->id
            ]);
        }
    }

    /**
     * Обновление метрик группы объявлений
     */
    private function updateAdGroupMetrics($adGroup): void
    {
        $last30DaysStats = $adGroup->statistics()
            ->where('stats_date', '>=', now()->subDays(30))
            ->get();

        $metrics = [
            'total_impressions_30d' => $last30DaysStats->sum('impressions'),
            'total_clicks_30d' => $last30DaysStats->sum('clicks'),
            'total_spend_30d' => $last30DaysStats->sum('spend'),
            'avg_ctr_30d' => $last30DaysStats->avg('ctr'),
            'avg_cpc_30d' => $last30DaysStats->avg('cpc'),
            'avg_cpm_30d' => $last30DaysStats->avg('cpm'),
            'best_day_impressions' => $last30DaysStats->max('impressions'),
            'best_day_clicks' => $last30DaysStats->max('clicks'),
            'updated_at' => now()
        ];

        Cache::put("vk_ads_ad_group_metrics_{$adGroup->id}", $metrics, now()->addHours(2));
    }

    /**
     * Обновление метрик кампании
     */
    private function updateCampaignMetrics($campaign): void
    {
        $campaignStats = \Modules\VkAds\app\Models\VkAdsStatistics::whereHas('adGroup', function ($query) use ($campaign) {
            $query->where('vk_ads_campaign_id', $campaign->id);
        })->where('stats_date', '>=', now()->subDays(30))->get();

        $metrics = [
            'total_impressions_30d' => $campaignStats->sum('impressions'),
            'total_clicks_30d' => $campaignStats->sum('clicks'),
            'total_spend_30d' => $campaignStats->sum('spend'),
            'avg_ctr_30d' => $campaignStats->avg('ctr'),
            'avg_cpc_30d' => $campaignStats->avg('cpc'),
            'avg_cpm_30d' => $campaignStats->avg('cpm'),
            'ad_groups_with_stats' => $campaignStats->pluck('vk_ads_ad_group_id')->unique()->count(),
            'days_with_activity' => $campaignStats->pluck('stats_date')->unique()->count(),
            'updated_at' => now()
        ];

        Cache::put("vk_ads_campaign_metrics_{$campaign->id}", $metrics, now()->addHours(2));
    }

    /**
     * Обновление метрик аккаунта
     */
    private function updateAccountMetrics($account): void
    {
        $accountStats = \Modules\VkAds\app\Models\VkAdsStatistics::whereHas('adGroup.campaign', function ($query) use ($account) {
            $query->where('vk_ads_account_id', $account->id);
        })->where('stats_date', '>=', now()->subDays(30))->get();

        $metrics = [
            'total_impressions_30d' => $accountStats->sum('impressions'),
            'total_clicks_30d' => $accountStats->sum('clicks'),
            'total_spend_30d' => $accountStats->sum('spend'),
            'avg_ctr_30d' => $accountStats->avg('ctr'),
            'avg_cpc_30d' => $accountStats->avg('cpc'),
            'campaigns_with_stats' => $accountStats->pluck('vk_ads_ad_group_id')
                ->map(fn($id) => \Modules\VkAds\app\Models\VkAdsAdGroup::find($id)?->vk_ads_campaign_id)
                ->filter()
                ->unique()
                ->count(),
            'updated_at' => now()
        ];

        Cache::put("vk_ads_account_metrics_{$account->id}", $metrics, now()->addHours(2));
    }

    /**
     * Получение трат за сегодня для кампании
     */
    private function getTodaySpendForCampaign($campaign): float
    {
        return \Modules\VkAds\app\Models\VkAdsStatistics::whereHas('adGroup', function ($query) use ($campaign) {
            $query->where('vk_ads_campaign_id', $campaign->id);
        })->where('stats_date', today())->sum('spend');
    }

    /**
     * Пересчет агрегированных метрик для группы объявлений
     */
    private function recalculateAggregatedMetricsForAdGroup(int $adGroupId): void
    {
        $adGroup = \Modules\VkAds\app\Models\VkAdsAdGroup::find($adGroupId);

        if ($adGroup) {
            $this->updateAdGroupMetrics($adGroup);
            $this->updateCampaignMetrics($adGroup->campaign);
            $this->updateAccountMetrics($adGroup->campaign->account);
        }
    }

    /**
     * Детекция аномалий в статистике
     */
    private function detectStatisticsAnomalies(VkAdsStatistics $statistics): void
    {
        $adGroup = $statistics->adGroup;

        // Получаем историческую статистику для сравнения
        $historicalStats = $adGroup->statistics()
            ->where('stats_date', '<', $statistics->stats_date)
            ->where('stats_date', '>=', $statistics->stats_date->copy()->subDays(30))
            ->get();

        if ($historicalStats->count() < 5) {
            return; // Недостаточно данных для анализа
        }

        $avgSpend = $historicalStats->avg('spend');
        $avgImpressions = $historicalStats->avg('impressions');
        $avgClicks = $historicalStats->avg('clicks');

        $anomalies = [];

        // Проверяем аномальные траты (отклонение больше 200%)
        if ($avgSpend > 0 && abs($statistics->spend - $avgSpend) > ($avgSpend * 2)) {
            $anomalies[] = [
                'metric' => 'spend',
                'value' => $statistics->spend,
                'average' => $avgSpend,
                'deviation_percent' => (($statistics->spend - $avgSpend) / $avgSpend) * 100
            ];
        }

        // Проверяем аномальные показы
        if ($avgImpressions > 0 && abs($statistics->impressions - $avgImpressions) > ($avgImpressions * 3)) {
            $anomalies[] = [
                'metric' => 'impressions',
                'value' => $statistics->impressions,
                'average' => $avgImpressions,
                'deviation_percent' => (($statistics->impressions - $avgImpressions) / $avgImpressions) * 100
            ];
        }

        // Логируем обнаруженные аномалии
        if (!empty($anomalies)) {
            Log::warning('Statistics anomalies detected', [
                'statistics_id' => $statistics->id,
                'ad_group_id' => $statistics->vk_ads_ad_group_id,
                'stats_date' => $statistics->stats_date->format('Y-m-d'),
                'anomalies' => $anomalies
            ]);
        }
    }

    /**
     * Сброс кэша статистики
     */
    private function invalidateStatisticsCache(VkAdsStatistics $statistics): void
    {
        // Сбрасываем кэш конкретной статистики
        Cache::forget("vk_ads_statistics_{$statistics->id}");

        // Сбрасываем кэш статистики группы объявлений
        Cache::forget("adgroup_stats_{$statistics->vk_ads_ad_group_id}");

        // Сбрасываем кэш статистики кампании
        $campaignId = $statistics->adGroup->vk_ads_campaign_id;
        Cache::forget("campaign_stats_{$campaignId}");

        // Сбрасываем кэш статистики аккаунта
        $accountId = $statistics->adGroup->campaign->vk_ads_account_id;
        Cache::forget("account_stats_{$accountId}");

        // Сбрасываем общие кэши
        Cache::tags(['vk-ads-statistics'])->flush();
        Cache::forget('agency_dashboard');
    }

    /**
     * Валидация данных статистики
     */
    private function validateStatisticsData(VkAdsStatistics $statistics): void
    {
        $errors = [];

        // Проверяем логичность метрик
        if ($statistics->clicks > $statistics->impressions) {
            $errors[] = 'Clicks cannot be greater than impressions';
        }

        if ($statistics->spend < 0) {
            $errors[] = 'Spend cannot be negative';
        }

        if ($statistics->ctr > 100) {
            $errors[] = 'CTR cannot be greater than 100%';
        }

        // Проверяем соответствие расчетных метрик
        if ($statistics->impressions > 0) {
            $calculatedCTR = ($statistics->clicks / $statistics->impressions) * 100;
            if (abs($calculatedCTR - $statistics->ctr) > 0.1) {
                $errors[] = 'CTR does not match calculated value';
            }
        }

        if ($statistics->clicks > 0) {
            $calculatedCPC = $statistics->spend / $statistics->clicks;
            if (abs($calculatedCPC - $statistics->cpc) > 0.01) {
                $errors[] = 'CPC does not match calculated value';
            }
        }

        // Логируем ошибки валидации
        if (!empty($errors)) {
            Log::error('Statistics validation errors', [
                'statistics_id' => $statistics->id,
                'errors' => $errors,
                'data' => [
                    'impressions' => $statistics->impressions,
                    'clicks' => $statistics->clicks,
                    'spend' => $statistics->spend,
                    'ctr' => $statistics->ctr,
                    'cpc' => $statistics->cpc,
                    'cpm' => $statistics->cpm
                ]
            ]);
        }
    }

    /**
     * Мониторинг качества данных
     */
    private function monitorDataQuality(VkAdsStatistics $statistics): void
    {
        // Проверяем свежесть данных
        $dataAge = now()->diffInHours($statistics->stats_date);

        if ($dataAge > 48) { // Данные старше 48 часов
            Log::info('Old statistics data detected', [
                'statistics_id' => $statistics->id,
                'stats_date' => $statistics->stats_date->format('Y-m-d'),
                'data_age_hours' => $dataAge
            ]);
        }

        // Проверяем полноту данных
        $missingMetrics = [];
        $requiredMetrics = ['impressions', 'clicks', 'spend'];

        foreach ($requiredMetrics as $metric) {
            if ($statistics->$metric === null) {
                $missingMetrics[] = $metric;
            }
        }

        if (!empty($missingMetrics)) {
            Log::warning('Incomplete statistics data', [
                'statistics_id' => $statistics->id,
                'missing_metrics' => $missingMetrics
            ]);
        }

        // Детектируем аномалии
        $this->detectStatisticsAnomalies($statistics);
    }

    /**
     * Логирование действий со статистикой для аудита
     */
    private function logStatisticsAction(VkAdsStatistics $statistics, string $action, array $changes = []): void
    {
        $logData = [
            'module' => 'VkAds',
            'model' => 'VkAdsStatistics',
            'action' => $action,
            'statistics_id' => $statistics->id,
            'ad_group_id' => $statistics->vk_ads_ad_group_id,
            'stats_date' => $statistics->stats_date->format('Y-m-d'),
            'period_type' => $statistics->period_type,
            'impressions' => $statistics->impressions,
            'clicks' => $statistics->clicks,
            'spend' => $statistics->spend,
            'sale_item_id' => $statistics->sale_item_id,
            'timestamp' => now()->toISOString(),
            'user_id' => auth()->id(),
            'ip_address' => request()->ip()
        ];

        // Добавляем изменения, если есть
        if (!empty($changes)) {
            $logData['changes'] = $changes;
        }

        // Добавляем контекстную информацию
        if ($statistics->adGroup) {
            $logData['ad_group_name'] = $statistics->adGroup->name;
            $logData['campaign_id'] = $statistics->adGroup->vk_ads_campaign_id;
            $logData['campaign_name'] = $statistics->adGroup->campaign->name ?? null;
        }

        if ($statistics->saleItem) {
            $logData['sale_id'] = $statistics->saleItem->sale_id;
            $logData['sale_product_name'] = $statistics->saleItem->product_name;
        }

        // Записываем в лог аудита
        Log::channel('audit')->info("VkAdsStatistics.{$action}", $logData);

        // Если есть таблица аудита, записываем туда
        if (class_exists('\App\Models\AuditLog')) {
            \App\Models\AuditLog::create([
                'model_type' => VkAdsStatistics::class,
                'model_id' => $statistics->id,
                'action' => $action,
                'changes' => $changes,
                'user_id' => auth()->id(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now()
            ]);
        }
    }
}
