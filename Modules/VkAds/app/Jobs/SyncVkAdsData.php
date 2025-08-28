<?php

namespace Modules\VkAds\app\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\VkAds\app\Models\VkAdsAccount;
use Modules\VkAds\app\Services\VkAdsAccountService;
use Modules\VkAds\app\Services\VkAdsCampaignService;
use Modules\VkAds\app\Services\VkAdsStatisticsService;

class SyncVkAdsData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 минут

    public int $tries = 3;

    public function __construct(
        public ?int $accountId = null,
        public bool $syncStatistics = true,
        public ?Carbon $statisticsDate = null
    ) {
        $this->onQueue('vk-ads-sync');
    }

    public function handle(
        VkAdsAccountService $accountService,
        VkAdsCampaignService $campaignService,
        VkAdsStatisticsService $statisticsService
    ): void {
        try {
            if ($this->accountId) {
                $accounts = collect([VkAdsAccount::findOrFail($this->accountId)]);
            } else {
                $accounts = VkAdsAccount::where('sync_enabled', true)->get();
            }

            foreach ($accounts as $account) {
                $this->syncAccount($account, $accountService, $campaignService, $statisticsService);
            }

        } catch (\Exception $e) {
            \Log::error('VK Ads sync job failed: '.$e->getMessage(), [
                'account_id' => $this->accountId,
                'exception' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    private function syncAccount(
        VkAdsAccount $account,
        VkAdsAccountService $accountService,
        VkAdsCampaignService $campaignService,
        VkAdsStatisticsService $statisticsService
    ): void {
        \Log::info("Starting sync for account {$account->id}");

        // Синхронизируем аккаунт
        $accountService->syncAccountFromVk($account->vk_account_id);

        // Синхронизируем кампании
        $campaignService->syncAllCampaigns($account);

        // Синхронизируем статистику, если требуется
        if ($this->syncStatistics) {
            $this->syncAccountStatistics($account, $statisticsService);
        }

        \Log::info("Completed sync for account {$account->id}");
    }

    private function syncAccountStatistics(VkAdsAccount $account, VkAdsStatisticsService $statisticsService): void
    {
        $date = $this->statisticsDate ?? Carbon::yesterday();

        // Синхронизируем статистику за указанную дату для всех кампаний аккаунта
        foreach ($account->campaigns as $campaign) {
            foreach ($campaign->adGroups as $adGroup) {
                try {
                    // Здесь можно добавить логику синхронизации статистики для конкретной группы
                    // $statisticsService->syncAdGroupStatistics($adGroup, $date);
                } catch (\Exception $e) {
                    \Log::warning("Failed to sync statistics for ad group {$adGroup->id}: ".$e->getMessage());
                }
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error('VK Ads sync job failed', [
            'account_id' => $this->accountId,
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
