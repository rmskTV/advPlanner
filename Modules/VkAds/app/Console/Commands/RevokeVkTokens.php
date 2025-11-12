<?php

namespace Modules\VkAds\app\Console\Commands;

use Illuminate\Console\Command;

class RevokeVkTokens extends Command
{
    protected $signature = 'vk-ads:revoke-tokens';

    protected $description = 'Отозвать токены клиента';

    /**
     * Execute the console command.
     */
    // в app/Console/Commands/RevokeVkTokens.php
    public function handle()
    {
        $accountId = $this->ask('Enter VK Ads Account ID:');
        $account = \Modules\VkAds\app\Models\VkAdsAccount::find($accountId);

        if (! $account) {
            $this->error('Account not found');

            return;
        }

        $service = app(\Modules\VkAds\app\Services\VkAdsApiService::class);
        $result = $service->revokeAllTokens($account);

        if ($result) {
            $this->info("✅ Все токены успешно отозваны для аккаунта {$account->id}");
        } else {
            $this->error('❌ Ошибка при отзыве токенов');
        }
    }
}
