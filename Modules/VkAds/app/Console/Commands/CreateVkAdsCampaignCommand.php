<?php

namespace Modules\VkAds\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\VkAds\app\Services\VkAdsCampaignService;

class CreateVkAdsCampaignCommand extends Command
{
    protected $signature = 'vk-ads:create-campaign {account-id} {--name= : Название кампании}';

    protected $description = 'Создать рекламную кампанию VK Ads';

    public function handle(VkAdsCampaignService $campaignService): int
    {
        $accountId = (int) $this->argument('account-id');

        try {
            $params = [];
            if ($name = $this->option('name')) {
                $params['name'] = $name;
            }

            $this->info('Создание кампании VK Ads...');

            $campaign = $campaignService->createCampaign($accountId, $params);

            $this->info('✅ Кампания создана успешно!');
            $this->info("  ID: {$campaign->id}");
            $this->info("  VK Campaign ID: {$campaign->vk_campaign_id}");
            $this->info("  Название: {$campaign->name}");
            $this->info("  Бюджет: {$campaign->budget_limit}");

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Ошибка: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
