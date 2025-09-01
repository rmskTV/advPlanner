<?php

namespace Modules\VkAds\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\VkAds\app\Services\VkAdsAccountService;
use Modules\VkAds\app\Services\VkAdsCampaignService;
use Modules\VkAds\app\Services\VkAdsAdGroupService;
use Modules\VkAds\app\Models\VkAdsAccount;

class SyncVkAdsCommand extends Command
{
    protected $signature = 'vk-ads:sync {--account-id= : ID конкретного аккаунта} {--test : Тестовый режим}';
    protected $description = 'Синхронизация данных VK Ads';

    public function handle(
        VkAdsAccountService $accountService,
        VkAdsCampaignService $campaignService,
        VkAdsAdGroupService $adGroupService
    ): int {
        $this->info('=== Синхронизация VK Ads ===');

        try {
            if ($accountId = $this->option('account-id')) {
                $accounts = collect([VkAdsAccount::findOrFail($accountId)]);
            } else {
                $accounts = VkAdsAccount::where('account_status', 'active')->get();
            }

            $this->info("Синхронизация {$accounts->count()} аккаунтов");

            foreach ($accounts as $account) {
                $this->info("Синхронизация: {$account->account_name} (ID: {$account->id}, VK ID: {$account->vk_account_id}, Тип: {$account->account_type})");

                try {
                    // Проверяем токен
                    $token = $account->getValidToken();
                    if (!$token) {
                        $this->warn("  - Нет валидного токена, создаем новый...");
                    } else {
                        $this->line("  - Токен найден, истекает: {$token->expires_at}");
                    }

                    // Синхронизируем данные аккаунта
                    $accountService->syncAccount($account->id);
                    $this->line("  ✓ Данные аккаунта обновлены");

                    if (!$this->option('test')) {
                        // ИСПРАВЛЕНО: сначала кампании, потом группы
                        $campaigns = $campaignService->syncAllCampaigns($account);
                        $this->line("  ✓ Синхронизировано кампаний: " . $campaigns->count());

                        // Синхронизируем группы объявлений для всех кампаний
                        if ($campaigns->isNotEmpty()) {
                            $adGroups = $adGroupService->syncAdGroupsForCampaigns($account, $campaigns);
                            $this->line("  ✓ Синхронизировано групп объявлений: " . $adGroups->count());
                        }

                        // Синхронизируем креативы (если доступны)
                        $creatives = $accountService->syncCreatives($account);
                        $this->line("  ✓ Синхронизировано креативов: " . count($creatives));
                    }

                } catch (\Exception $e) {
                    $this->error("  ✗ Ошибка: " . $e->getMessage());

                    if ($this->option('verbose')) {
                        $this->line("  Детали ошибки:");
                        $this->line("  " . $e->getTraceAsString());
                    }
                }
            }

            $this->info('Синхронизация завершена');
            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Критическая ошибка: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
