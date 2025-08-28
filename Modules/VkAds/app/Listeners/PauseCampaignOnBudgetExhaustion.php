<?php

namespace Modules\VkAds\app\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\VkAds\app\Events\BudgetExhausted;
use Modules\VkAds\app\Services\VkAdsCampaignService;

class PauseCampaignOnBudgetExhaustion implements ShouldQueue
{
    public function __construct(
        private VkAdsCampaignService $campaignService
    ) {}

    public function handle(BudgetExhausted $event): void
    {
        $campaign = $event->campaign;

        // Автоматически паузим кампанию при исчерпании бюджета
        if (config('vkads.budget.auto_pause_on_exhaustion', true)) {
            try {
                $this->campaignService->pauseCampaign($campaign->id);

                \Log::info('Campaign automatically paused due to budget exhaustion', [
                    'campaign_id' => $campaign->id,
                    'campaign_name' => $campaign->name,
                    'current_spend' => $event->currentSpend,
                    'budget_limit' => $event->budgetLimit,
                ]);

            } catch (\Exception $e) {
                \Log::error('Failed to auto-pause campaign on budget exhaustion: '.$e->getMessage(), [
                    'campaign_id' => $campaign->id,
                ]);
            }
        }
    }
}
