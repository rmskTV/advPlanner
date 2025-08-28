<?php

namespace Modules\VkAds\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\VkAds\app\Jobs\SyncVkAdsData;
use Modules\VkAds\app\Models\VkAdsAccount;

class SyncVkAdsCommand extends Command
{
    protected $signature = 'vk-ads:sync
                           {--account-id= : ID конкретного аккаунта для синхронизации}
                           {--statistics : Синхронизировать статистику}
                           {--date= : Дата для синхронизации статистики (Y-m-d)}';

    protected $description = 'Синхронизация данных VK Ads';

    public function handle(): int
    {
        $accountId = $this->option('account-id');
        $syncStatistics = $this->option('statistics');
        $date = $this->option('date') ? \Carbon\Carbon::parse($this->option('date')) : null;

        if ($accountId) {
            $account = VkAdsAccount::findOrFail($accountId);
            $this->info("Запуск синхронизации для аккаунта {$account->account_name}");
        } else {
            $this->info('Запуск синхронизации для всех аккаунтов');
        }

        SyncVkAdsData::dispatch($accountId, $syncStatistics, $date);

        $this->info('Задача синхронизации добавлена в очередь');

        return self::SUCCESS;
    }
}
