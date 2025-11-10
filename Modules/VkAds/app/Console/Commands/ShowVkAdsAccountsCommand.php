<?php

namespace Modules\VkAds\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\VkAds\app\Models\VkAdsAccount;

class ShowVkAdsAccountsCommand extends Command
{
    protected $signature = 'vk-ads:show-accounts';

    protected $description = 'Показать все синхронизированные VK Ads аккаунты';

    public function handle(): int
    {
        $accounts = VkAdsAccount::with(['organization', 'contract'])
            ->orderBy('account_type')
            ->orderBy('account_name')
            ->get();

        if ($accounts->isEmpty()) {
            $this->warn('Нет синхронизированных аккаунтов');

            return self::SUCCESS;
        }

        $this->info('=== VK Ads Аккаунты ===');

        $agencyAccounts = $accounts->where('account_type', 'agency');
        $clientAccounts = $accounts->where('account_type', 'client');

        if ($agencyAccounts->isNotEmpty()) {
            $this->line("\n🏢 Агентские аккаунты:");
            foreach ($agencyAccounts as $account) {
                $this->line("   ID: {$account->id} | VK ID: {$account->vk_account_id} | {$account->account_name}");
                $this->line("   Статус: {$account->account_status} | Баланс: {$account->balance} {$account->currency}");
                $this->line('   Последняя синхронизация: '.($account->last_sync_at ? $account->last_sync_at->format('Y-m-d H:i:s') : 'Никогда'));
                $this->line('');
            }
        }

        if ($clientAccounts->isNotEmpty()) {
            $this->line("\n👤 Клиентские аккаунты:");
            foreach ($clientAccounts as $account) {
                $this->line("   ID: {$account->id} | VK ID: {$account->vk_account_id} | {$account->account_name}");
                $this->line("   Статус: {$account->account_status} | Баланс: {$account->balance} {$account->currency}");
                $this->line('   Последняя синхронизация: '.($account->last_sync_at ? $account->last_sync_at->format('Y-m-d H:i:s') : 'Никогда'));

                if ($account->contract) {
                    $this->line("   Договор: {$account->contract->number}");
                }
                $this->line('');
            }
        }

        $this->info("Всего аккаунтов: {$accounts->count()}");
        $this->info("Агентских: {$agencyAccounts->count()}, Клиентских: {$clientAccounts->count()}");

        return self::SUCCESS;
    }
}
