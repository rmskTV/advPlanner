<?php

namespace Modules\VkAds\app\Observers;

use Modules\VkAds\app\Events\BudgetExhausted;
use Modules\VkAds\app\Models\VkAdsCampaign;

class VkAdsCampaignObserver
{
    /**
     * Обработка после обновления кампании
     */
    public function updated(VkAdsCampaign $campaign): void
    {
        // Проверяем бюджет после обновления статистики
        if ($campaign->isDirty(['daily_budget', 'total_budget'])) {
            $this->checkBudgetLimits($campaign);
        }

        // Проверяем производительность
        if ($campaign->isDirty('status') && $campaign->status === 'active') {
            $this->schedulePerformanceCheck($campaign);
        }
    }

    /**
     * Обработка после создания кампании
     */
    public function created(VkAdsCampaign $campaign): void
    {
        // Логируем создание кампании
        \Log::info("Campaign created: {$campaign->name}", [
            'campaign_id' => $campaign->id,
            'vk_campaign_id' => $campaign->vk_campaign_id,
            'account_id' => $campaign->vk_ads_account_id,
        ]);

        // Сбрасываем кэш аккаунта
        \Cache::tags(['vk-ads-campaigns'])->flush();
    }

    private function checkBudgetLimits(VkAdsCampaign $campaign): void
    {
        $currentSpend = $campaign->getTotalSpend();
        $budgetLimit = $campaign->daily_budget ?? $campaign->total_budget;

        if ($budgetLimit && $currentSpend >= $budgetLimit) {
            BudgetExhausted::dispatch($campaign, $currentSpend, $budgetLimit);
        }
    }

    private function schedulePerformanceCheck(VkAdsCampaign $campaign): void
    {
        // Запланировать проверку производительности через час после активации
        \Modules\VkAds\app\Jobs\CheckCampaignPerformance::dispatch($campaign)
            ->delay(now()->addHour());
    }
}
