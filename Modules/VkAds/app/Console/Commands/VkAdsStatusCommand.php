<?php

namespace Modules\VkAds\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\VkAds\app\Models\VkAdsAccount;
use Modules\VkAds\app\Models\VkAdsCampaign;
use Modules\VkAds\app\Models\VkAdsCreative;

class VkAdsStatusCommand extends Command
{
    protected $signature = 'vk-ads:status {--detailed : Показать детальную информацию}';

    protected $description = 'Показать статус модуля VK Ads';

    public function handle(): int
    {
        $this->info('=== Статус модуля VK Ads ===');

        // Статистика по аккаунтам
        $agencyAccounts = VkAdsAccount::agency()->count();
        $clientAccounts = VkAdsAccount::client()->count();
        $activeAccounts = VkAdsAccount::active()->count();

        $this->table(['Тип', 'Количество'], [
            ['Агентские аккаунты', $agencyAccounts],
            ['Клиентские аккаунты', $clientAccounts],
            ['Активные аккаунты', $activeAccounts],
        ]);

        // Статистика по кампаниям
        $activeCampaigns = VkAdsCampaign::active()->count();
        $pausedCampaigns = VkAdsCampaign::paused()->count();
        $totalCampaigns = VkAdsCampaign::count();

        $this->table(['Статус кампаний', 'Количество'], [
            ['Активные', $activeCampaigns],
            ['На паузе', $pausedCampaigns],
            ['Всего', $totalCampaigns],
        ]);

        // Статистика по креативам
        $approvedCreatives = VkAdsCreative::approved()->count();
        $pendingCreatives = VkAdsCreative::where('moderation_status', 'pending')->count();
        $videoCreatives = VkAdsCreative::video()->count();
        $instreamCreatives = VkAdsCreative::instream()->count();

        $this->table(['Креативы', 'Количество'], [
            ['Одобренные', $approvedCreatives],
            ['На модерации', $pendingCreatives],
            ['Видео', $videoCreatives],
            ['Instream', $instreamCreatives],
        ]);

        if ($this->option('detailed')) {
            $this->showDetailedStatus();
        }

        return self::SUCCESS;
    }

    private function showDetailedStatus(): void
    {
        $this->newLine();
        $this->info('=== Детальная информация ===');

        // Последние синхронизации
        $lastSyncedAccounts = VkAdsAccount::whereNotNull('last_sync_at')
            ->orderBy('last_sync_at', 'desc')
            ->limit(5)
            ->get(['id', 'account_name', 'last_sync_at']);

        if ($lastSyncedAccounts->isNotEmpty()) {
            $this->info('Последние синхронизации аккаунтов:');
            foreach ($lastSyncedAccounts as $account) {
                $this->line("- {$account->account_name}: {$account->last_sync_at->diffForHumans()}");
            }
        }

        // Проблемы с модерацией
        $rejectedItems = collect();

        $rejectedCreatives = VkAdsCreative::where('moderation_status', 'rejected')->count();
        $rejectedAds = \Modules\VkAds\app\Models\VkAdsAd::where('moderation_status', 'rejected')->count();

        if ($rejectedCreatives > 0 || $rejectedAds > 0) {
            $this->warn('Отклоненные модерацией:');
            $this->line("- Креативы: {$rejectedCreatives}");
            $this->line("- Объявления: {$rejectedAds}");
        }
    }
}
