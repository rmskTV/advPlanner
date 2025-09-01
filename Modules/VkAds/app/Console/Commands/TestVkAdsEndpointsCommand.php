<?php

namespace Modules\VkAds\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\VkAds\app\Services\VkAdsApiService;
use Modules\VkAds\app\Models\VkAdsAccount;

class TestVkAdsEndpointsCommand extends Command
{
    protected $signature = 'vk-ads:test-endpoints {account-id=1}';
    protected $description = 'Тестирование различных endpoints VK Ads API';

    public function handle(VkAdsApiService $apiService): int
    {
        $accountId = $this->argument('account-id');

        try {
            $account = VkAdsAccount::findOrFail($accountId);
            $this->info("Тестирование endpoints для аккаунта: {$account->account_name}");

            // Список endpoints для тестирования
            $endpoints = [
                'accounts' => 'Получение списка аккаунтов',
                'agency/clients' => 'Получение клиентов агентства (только для агентских аккаунтов)',
                'campaigns' => 'Получение кампаний',
                'creatives' => 'Получение креативов',
            ];

            foreach ($endpoints as $endpoint => $description) {
                $this->line("\nТестирование: {$description}");
                $this->line("Endpoint: {$endpoint}");

                try {
                    // Пропускаем agency/clients для клиентских аккаунтов
                    if ($endpoint === 'agency/clients' && !$account->isAgency()) {
                        $this->warn("   - Пропущен (только для агентских аккаунтов)");
                        continue;
                    }

                    $result = $apiService->makeAuthenticatedRequest($account, $endpoint);

                    if (is_array($result)) {
                        $this->info("   ✓ Успешно. Получено записей: " . count($result));

                        // Показываем первую запись для диагностики
                        if (!empty($result) && $this->option('verbose')) {
                            $this->line("   Пример данных: " . json_encode(array_slice($result[0] ?? $result, 0, 3), JSON_UNESCAPED_UNICODE));
                        }
                    } else {
                        $this->info("   ✓ Успешно. Получен ответ: " . gettype($result));
                    }

                } catch (\Exception $e) {
                    $this->error("   ✗ Ошибка: " . $e->getMessage());
                }
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Критическая ошибка: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
