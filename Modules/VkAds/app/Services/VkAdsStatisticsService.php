<?php

namespace Modules\VkAds\app\Services;

use App\RedisCacheService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Modules\VkAds\app\DTOs\StatisticsDTO;
use Modules\VkAds\app\DTOs\StatisticsRequestDTO;
use Modules\VkAds\app\Exceptions\VkAdsAuthenticationException;
use Modules\VkAds\app\Models\VkAdsAccount;
use Modules\VkAds\app\Models\VkAdsAdGroup;
use Modules\VkAds\app\Models\VkAdsCampaign;
use Modules\VkAds\app\Models\VkAdsStatistics;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VkAdsStatisticsService
{
    private VkAdsApiService $apiService;

    private RedisCacheService $cache;

    public function __construct(VkAdsApiService $apiService, RedisCacheService $cache)
    {
        $this->apiService = $apiService;
        $this->cache = $cache;
    }

    // === ПОЛУЧЕНИЕ СТАТИСТИКИ ===

    public function getCampaignStatistics(int $campaignId, StatisticsRequestDTO $request): StatisticsDTO
    {
        $campaign = VkAdsCampaign::with('adGroups')->findOrFail($campaignId);

        $cacheKey = "campaign_stats_{$campaignId}_".$request->getCacheKey();
        $cached = $this->getCachedStatistics($cacheKey);

        if ($cached) {
            return $cached;
        }

        // Получаем статистику по всем группам объявлений кампании
        $adGroupIds = $campaign->adGroups->pluck('id')->toArray();
        $statistics = $this->getAggregatedStatisticsFromDb($adGroupIds, $request);

        // Если нет данных в БД, запрашиваем из VK API
        if ($statistics->isEmpty()) {
            $statistics = $this->fetchCampaignStatsFromVk($campaign, $request);
        }

        $dto = StatisticsDTO::fromCollection($statistics, $request->metrics);
        $this->cacheStatistics($cacheKey, $dto, $request->getCacheTTL());

        return $dto;
    }

    public function getAdGroupStatistics(int $adGroupId, StatisticsRequestDTO $request): StatisticsDTO
    {
        $adGroup = VkAdsAdGroup::findOrFail($adGroupId);

        $cacheKey = "adgroup_stats_{$adGroupId}_".$request->getCacheKey();
        $cached = $this->getCachedStatistics($cacheKey);

        if ($cached) {
            return $cached;
        }

        $statistics = VkAdsStatistics::where('vk_ads_ad_group_id', $adGroupId)
            ->whereBetween('stats_date', [$request->dateFrom, $request->dateTo])
            ->when($request->groupBy !== 'day', function ($query) use ($request) {
                return $this->applyGrouping($query, $request->groupBy);
            })
            ->get();

        if ($statistics->isEmpty()) {
            $statistics = $this->fetchAdGroupStatsFromVk($adGroup, $request);
        }

        $dto = StatisticsDTO::fromCollection($statistics, $request->metrics);
        $this->cacheStatistics($cacheKey, $dto, $request->getCacheTTL());

        return $dto;
    }

    public function getAccountStatistics(int $accountId, StatisticsRequestDTO $request): StatisticsDTO
    {
        $account = VkAdsAccount::with('campaigns.adGroups')->findOrFail($accountId);

        $cacheKey = "account_stats_{$accountId}_".$request->getCacheKey();
        $cached = $this->getCachedStatistics($cacheKey);

        if ($cached) {
            return $cached;
        }

        $adGroupIds = $account->campaigns
            ->flatMap->adGroups
            ->pluck('id')
            ->toArray();

        $statistics = $this->getAggregatedStatisticsFromDb($adGroupIds, $request);

        if ($statistics->isEmpty()) {
            $statistics = $this->fetchAccountStatsFromVk($account, $request);
        }

        $dto = StatisticsDTO::fromCollection($statistics, $request->metrics);
        $this->cacheStatistics($cacheKey, $dto, $request->getCacheTTL());

        return $dto;
    }

    // === АГРЕГИРОВАННАЯ СТАТИСТИКА ===

    public function getAggregatedStatistics(array $adGroupIds, StatisticsRequestDTO $request): Collection
    {
        return $this->getAggregatedStatisticsFromDb($adGroupIds, $request);
    }

    public function getComparativeStatistics(array $campaignIds, StatisticsRequestDTO $request): array
    {
        $result = [];

        foreach ($campaignIds as $campaignId) {
            $stats = $this->getCampaignStatistics($campaignId, $request);
            $result[$campaignId] = $stats->toArray();
        }

        return $result;
    }

    // === ЭКСПОРТ ДАННЫХ ===

    public function exportStatistics(StatisticsRequestDTO $request, string $format = 'csv'): StreamedResponse
    {
        return response()->streamDownload(function () use ($request, $format) {
            $handle = fopen('php://output', 'w');

            if ($format === 'csv') {
                $this->exportToCsv($handle, $request);
            } elseif ($format === 'xlsx') {
                $this->exportToXlsx($handle, $request);
            }

            fclose($handle);
        }, "vk_ads_statistics_{$request->dateFrom->format('Y-m-d')}_{$request->dateTo->format('Y-m-d')}.{$format}");
    }

    public function scheduleStatisticsReport(StatisticsRequestDTO $request, string $email): bool
    {
        // Запланировать отправку отчета по email
        \Modules\VkAds\app\Jobs\SendStatisticsReport::dispatch($request, $email)
            ->delay(now()->addMinutes(5));

        return true;
    }

    // === КЭШИРОВАНИЕ СТАТИСТИКИ ===

    public function getCachedStatistics(string $cacheKey): ?StatisticsDTO
    {
        $cached = $this->cache->get($cacheKey);

        if ($cached) {
            return StatisticsDTO::fromArray(json_decode($cached, true));
        }

        return null;
    }

    public function cacheStatistics(string $cacheKey, StatisticsDTO $data, int $ttl = 3600): void
    {
        $this->cache->set($cacheKey, json_encode($data->toArray()), [], $ttl);
    }

    // === МЕТРИКИ И KPI ===

    public function calculateKPIs(StatisticsDTO $statistics): array
    {
        return [
            'ctr' => $statistics->getTotalImpressions() > 0
                ? ($statistics->getTotalClicks() / $statistics->getTotalImpressions()) * 100
                : 0,
            'cpc' => $statistics->getTotalClicks() > 0
                ? $statistics->getTotalSpend() / $statistics->getTotalClicks()
                : 0,
            'cpm' => $statistics->getTotalImpressions() > 0
                ? ($statistics->getTotalSpend() / $statistics->getTotalImpressions()) * 1000
                : 0,
            'conversion_rate' => $statistics->getTotalConversions() > 0 && $statistics->getTotalClicks() > 0
                ? ($statistics->getTotalConversions() / $statistics->getTotalClicks()) * 100
                : 0,
            'cost_per_conversion' => $statistics->getTotalConversions() > 0
                ? $statistics->getTotalSpend() / $statistics->getTotalConversions()
                : 0,
        ];
    }

    public function getPerformanceTrends(int $campaignId, int $days = 30): array
    {
        $endDate = Carbon::now();
        $startDate = $endDate->copy()->subDays($days);

        $statistics = VkAdsStatistics::whereHas('adGroup', function ($query) use ($campaignId) {
            $query->where('vk_ads_campaign_id', $campaignId);
        })
            ->whereBetween('stats_date', [$startDate, $endDate])
            ->orderBy('stats_date')
            ->get()
            ->groupBy('stats_date');

        $trends = [];
        foreach ($statistics as $date => $dayStats) {
            $trends[$date] = [
                'spend' => $dayStats->sum('spend'),
                'impressions' => $dayStats->sum('impressions'),
                'clicks' => $dayStats->sum('clicks'),
                'ctr' => $dayStats->sum('impressions') > 0
                    ? ($dayStats->sum('clicks') / $dayStats->sum('impressions')) * 100
                    : 0,
            ];
        }

        return $trends;
    }

    // === ПРИВАТНЫЕ МЕТОДЫ ===

    private function getAggregatedStatisticsFromDb(array $adGroupIds, StatisticsRequestDTO $request): Collection
    {
        $query = VkAdsStatistics::whereIn('vk_ads_ad_group_id', $adGroupIds)
            ->whereBetween('stats_date', [$request->dateFrom, $request->dateTo]);

        if ($request->groupBy !== 'day') {
            $query = $this->applyGrouping($query, $request->groupBy);
        }

        return $query->get();
    }

    private function applyGrouping($query, string $groupBy)
    {
        switch ($groupBy) {
            case 'week':
                return $query->selectRaw('
                    YEARWEEK(stats_date) as period,
                    MIN(stats_date) as stats_date,
                    SUM(impressions) as impressions,
                    SUM(clicks) as clicks,
                    SUM(spend) as spend,
                    AVG(ctr) as ctr,
                    AVG(cpc) as cpc,
                    AVG(cpm) as cpm
                ')->groupBy('period');

            case 'month':
                return $query->selectRaw('
                    DATE_FORMAT(stats_date, "%Y-%m") as period,
                    MIN(stats_date) as stats_date,
                    SUM(impressions) as impressions,
                    SUM(clicks) as clicks,
                    SUM(spend) as spend,
                    AVG(ctr) as ctr,
                    AVG(cpc) as cpc,
                    AVG(cpm) as cpm
                ')->groupBy('period');

            default:
                return $query;
        }
    }

    /**
     * @throws VkAdsAuthenticationException
     */
    private function fetchCampaignStatsFromVk(VkAdsCampaign $campaign, StatisticsRequestDTO $request): Collection
    {
        $vkStats = $this->apiService->makeAuthenticatedRequest(
            $campaign->account,
            'campaigns.getStatistics',
            array_merge($request->toVkAdsParams(), [
                'campaign_id' => $campaign->vk_campaign_id,
            ])
        );

        return $this->saveVkStatsToDb($vkStats, $campaign->adGroups);
    }

    /**
     * @throws VkAdsAuthenticationException
     */
    private function fetchAdGroupStatsFromVk(VkAdsAdGroup $adGroup, StatisticsRequestDTO $request): Collection
    {
        $vkStats = $this->apiService->makeAuthenticatedRequest(
            $adGroup->campaign->account,
            'adgroups.getStatistics',
            array_merge($request->toVkAdsParams(), [
                'adgroup_id' => $adGroup->vk_ad_group_id,
            ])
        );

        return $this->saveVkStatsToDb($vkStats, collect([$adGroup]));
    }

    /**
     * @throws VkAdsAuthenticationException
     */
    private function fetchAccountStatsFromVk(VkAdsAccount $account, StatisticsRequestDTO $request): Collection
    {
        $vkStats = $this->apiService->makeAuthenticatedRequest(
            $account,
            'accounts.getStatistics',
            array_merge($request->toVkAdsParams(), [
                'account_id' => $account->vk_account_id,
            ])
        );

        $adGroups = $account->campaigns->flatMap->adGroups;

        return $this->saveVkStatsToDb($vkStats, $adGroups);
    }

    private function saveVkStatsToDb(array $vkStats, Collection $adGroups): Collection
    {
        $savedStats = collect();

        foreach ($vkStats as $statData) {
            // Находим соответствующую группу объявлений
            $adGroup = $adGroups->firstWhere('vk_ad_group_id', $statData['adgroup_id'] ?? null);

            if (! $adGroup) {
                continue;
            }

            $stat = VkAdsStatistics::updateOrCreate([
                'vk_ads_ad_group_id' => $adGroup->id,
                'stats_date' => Carbon::parse($statData['date']),
                'period_type' => 'day',
            ], [
                'impressions' => $statData['impressions'] ?? 0,
                'clicks' => $statData['clicks'] ?? 0,
                'spend' => ($statData['spend'] ?? 0) / 100, // VK возвращает в копейках
                'ctr' => $statData['ctr'] ?? 0,
                'cpc' => ($statData['cpc'] ?? 0) / 100,
                'cpm' => ($statData['cpm'] ?? 0) / 100,
            ]);

            $savedStats->push($stat);
        }

        return $savedStats;
    }

    private function exportToCsv($handle, StatisticsRequestDTO $request): void
    {
        // Заголовки CSV
        fputcsv($handle, [
            'Дата', 'Группа объявлений', 'Кампания', 'Показы', 'Клики',
            'Расход', 'CTR (%)', 'CPC', 'CPM',
        ]);

        // Получаем все статистики для экспорта
        $statistics = VkAdsStatistics::with(['adGroup.campaign'])
            ->whereBetween('stats_date', [$request->dateFrom, $request->dateTo])
            ->orderBy('stats_date')
            ->get();

        foreach ($statistics as $stat) {
            fputcsv($handle, [
                $stat->stats_date->format('Y-m-d'),
                $stat->adGroup->name ?? '',
                $stat->adGroup->campaign->name ?? '',
                $stat->impressions,
                $stat->clicks,
                $stat->spend,
                round($stat->ctr, 2),
                $stat->cpc,
                $stat->cpm,
            ]);
        }
    }

    private function exportToXlsx($handle, StatisticsRequestDTO $request): void
    {
        // Для XLSX экспорта можно использовать библиотеку типа PhpSpreadsheet
        // Здесь упрощенная реализация через CSV
        $this->exportToCsv($handle, $request);
    }
}
