<?php

namespace Modules\VkAds\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\VkAds\app\Models\VkAdsAdGroup;
use Modules\VkAds\app\Services\VkAdsAdService;

class CreateVkAdsAdCommand extends Command
{
    protected $signature = 'vk-ads:create-ad {ad-group-id}
                           {--name= : Название объявления}
                           {--title= : Заголовок}
                           {--text= : Текст объявления}
                           {--url= : Ссылка}
                           {--content-file= : Путь к файлу контента}
                           {--content-type=static : Тип контента (static, video, html5)}
                           {--width= : Ширина контента}
                           {--height= : Высота контента}';

    protected $description = 'Создать объявление VK Ads';

    public function handle(VkAdsAdService $adService): int
    {
        $adGroupId = (int) $this->argument('ad-group-id');

        try {
            $adGroup = VkAdsAdGroup::with('account')->findOrFail($adGroupId);

            $this->info('Создание объявления для:');
            $this->info("  Группа: {$adGroup->name}");
            $this->info("  Кампания: {$adGroup->campaign->name}");
            $this->info("  Аккаунт: {$adGroup->account->account_name}");

            // Собираем параметры
            $params = array_filter([
                'name' => $this->option('name'),
                'title' => $this->option('title'),
                'text' => $this->option('text'),
                'url' => $this->option('url'),
                'content_file' => $this->option('content-file'),
                'content_type' => $this->option('content-type'),
                'width' => $this->option('width'),
                'height' => $this->option('height'),
            ]);

            $this->info('Создание объявления...');

            $ad = $adService->createAd($adGroup, $params);

            $this->info('✅ Объявление создано успешно!');
            $this->info("  ID: {$ad->id}");
            $this->info("  VK Ad ID: {$ad->vk_ad_id}");
            $this->info("  Название: {$ad->name}");
            $this->info("  Статус: {$ad->status}");
            $this->info("  Модерация: {$ad->moderation_status}");

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Ошибка: '.$e->getMessage());

            if ($this->option('verbose')) {
                $this->line($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }
}
