<?php

namespace Modules\VkAds\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\VkAds\app\Services\VkAdsApiService;
use Modules\VkAds\app\Services\VkAdsAccountService;
use Modules\VkAds\app\Models\VkAdsAccount;

class TestVkAdsConnectionCommand extends Command
{
    protected $signature = 'vk-ads:test-connection {account-id=1}';
    protected $description = 'Тестирование подключения к VK Ads API';

    public function handle(VkAdsApiService $apiService, VkAdsAccountService $accountService): int
    {
        $accountId = $this->argument('account-id');

        try {
            $account = VkAdsAccount::findOrFail($accountId);
            $this->info("Тестирование подключения для аккаунта: {$account->account_name}");

            // Тестируем получение токена
            $this->line('1. Получение токена...');
            $token = $account->getValidToken();
            if ($token) {
                $this->info("   ✓ Токен найден, истекает: {$token->expires_at}");
            } else {
                $this->warn('   ! Токен не найден, будет создан новый');
            }

            // Тестируем API запрос
            $this->line('2. Тестирование API запроса...');

            if ($account->isAgency()) {
                // Для агентского аккаунта тестируем получение клиентов
                $clients = $apiService->makeAuthenticatedRequest($account, 'agency/clients');
                $this->info("   ✓ Получено клиентов агентства: " . count($clients));

                // ИСПРАВЛЕНО: правильная обработка структуры данных
                if (!empty($clients)) {
                    $this->line('3. Клиенты агентства:');
                    foreach (array_slice($clients, 0, 5) as $client) {
                        $accountData = $client['user']['account'] ?? [];
                        $userData = $client['user'] ?? [];

                        $clientId = $accountData['id'] ?? $userData['id'] ?? 'N/A';
                        $clientName = $userData['client_username'] ?? 'N/A';
                        $balance = $accountData['balance'] ?? '0';

                        $this->line("   - ID: {$clientId}, Name: {$clientName}, Balance: {$balance} RUB");
                    }
                }
            } else {
                // Для клиентского аккаунта тестируем получение кампаний
                $campaigns = $apiService->makeAuthenticatedRequest($account, 'campaigns');
                $this->info("   ✓ Получено кампаний: " . count($campaigns));
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Ошибка подключения: ' . $e->getMessage());

            if ($this->option('verbose')) {
                $this->line('Детали ошибки:');
                $this->line($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }
}
