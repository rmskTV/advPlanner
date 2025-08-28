<?php

namespace Modules\VkAds\app\Listeners;

use App\RedisCacheService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Modules\VkAds\app\Events\SyncCompleted;

class UpdateStatisticsCache implements ShouldQueue
{
    public function __construct(
        private RedisCacheService $cache
    ) {}

    public function handle(SyncCompleted $event): void
    {
        try {
            // Сбрасываем кэш статистики после синхронизации
            if ($event->account) {
                $this->cache->forgetBySubstring("account_stats_{$event->account->id}");
                $this->cache->forgetBySubstring('campaign_stats_');
                $this->cache->forgetBySubstring('adgroup_stats_');
            } else {
                // Если синхронизировались все аккаунты
                $this->cache->forgetBySubstring('_stats_');
            }

            // Обновляем кэш дашборда
            $this->cache->forgetBySubstring('agency_dashboard');

            Log::info('Statistics cache updated after sync completion');

        } catch (\Exception $e) {
            Log::error('Failed to update statistics cache: '.$e->getMessage());
        }
    }
}
