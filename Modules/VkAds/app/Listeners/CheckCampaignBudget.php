<?php

namespace Modules\VkAds\app\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\VkAds\app\Events\BudgetExhausted;
use Modules\VkAds\app\Events\CampaignStatusChanged;

class CheckCampaignBudget implements ShouldQueue
{
    public function handle(CampaignStatusChanged $event): void
    {
        $campaign = $event->campaign;

        // Проверяем бюджет только для активных кампаний
        if ($event->newStatus !== 'active') {
            return;
        }

        $currentSpend = $campaign->getTotalSpend();
        $dailyBudget = $campaign->daily_budget;
        $totalBudget = $campaign->total_budget;

        // Проверяем дневной бюджет
        if ($dailyBudget) {
            $todaySpend = $campaign->adGroups()
                ->with(['statistics' => function ($query) {
                    $query->where('stats_date', today());
                }])
                ->get()
                ->flatMap->statistics
                ->sum('spend');

            if ($todaySpend >= $dailyBudget * 0.95) { // 95% от дневного бюджета
                BudgetExhausted::dispatch($campaign, $todaySpend, $dailyBudget);
            }
        }

        // Проверяем общий бюджет
        if ($totalBudget && $currentSpend >= $totalBudget * 0.95) { // 95% от общего бюджета
            BudgetExhausted::dispatch($campaign, $currentSpend, $totalBudget);
        }
    }
}
