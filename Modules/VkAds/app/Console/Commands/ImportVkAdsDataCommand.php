<?php

namespace Modules\VkAds\app\Console;

use Illuminate\Console\Command;
use Modules\VkAds\app\Services\VkAdsImportService;
use Carbon\Carbon;

class ImportVkAdsDataCommand extends Command
{
    protected $signature = 'vk-ads:import
                           {agency-vk-account-id : ID агентского аккаунта в VK Ads}
                           {access-token : Токен доступа к VK Ads API}
                           {--statistics-from= : Дата начала импорта статистики (Y-m-d)}
                           {--statistics-to= : Дата окончания импорта статистики (Y-m-d)}
                           {--skip-statistics : Пропустить импорт статистики}
                           {--dry-run : Тестовый запуск без сохранения в БД}';

    protected $description = 'Импорт существующих данных из VK Ads';

    public function handle(VkAdsImportService $importService): int
    {
        $agencyVkAccountId = (int) $this->argument('agency-vk-account-id');
        $accessToken = $this->argument('access-token');
        $isDryRun = $this->option('dry-run');

        $this->info("=== Импорт данных VK Ads ===");
        $this->info("Агентский аккаунт VK: {$agencyVkAccountId}");

        if ($isDryRun) {
            $this->warn("РЕЖИМ ТЕСТИРОВАНИЯ - данные не будут сохранены в БД");
        }

        // Подтверждение от пользователя
        if (!$this->confirm('Продолжить импорт?')) {
            $this->info('Импорт отменен');
            return self::SUCCESS;
        }

        try {
            $startTime = microtime(true);

            // Основной импорт
            $this->info('Начинаем импорт аккаунтов, кампаний и объявлений...');
            $results = $isDryRun
                ? $this->simulateImport($agencyVkAccountId, $accessToken)
                : $importService->importAllData($agencyVkAccountId, $accessToken);

            // Показываем результаты
            $this->displayImportResults($results);

            // Импорт статистики (если не пропускаем)
            if (!$this->option('skip-statistics') && !$isDryRun) {
                $this->importStatistics($importService, $results);
            }

            $duration = round(microtime(true) - $startTime, 2);
            $this->info("Импорт завершен за {$duration} секунд");

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Ошибка импорта: ' . $e->getMessage());
            $this->error('Подробности в логах');

            return self::FAILURE;
        }
    }

    private function displayImportResults(array $results): void
    {
        $this->info("\n=== Результаты импорта ===");

        $this->table(['Тип данных', 'Количество'], [
            ['Аккаунты', count($results['accounts'])],
            ['Кампании', count($results['campaigns'])],
            ['Группы объявлений', count($results['ad_groups'])],
            ['Креативы', count($results['creatives'])],
            ['Объявления', count($results['ads'])],
            ['Ошибки', count($results['errors'])]
        ]);

        // Показываем ошибки, если есть
        if (!empty($results['errors'])) {
            $this->warn("\nОшибки при импорте:");
            foreach ($results['errors'] as $error) {
                $this->line("- {$error}");
            }
        }

        // Детализация по аккаунтам
        if (!empty($results['accounts'])) {
            $this->info("\nИмпортированные аккаунты:");
            foreach ($results['accounts'] as $account) {
                $this->line("- {$account['account_name']} ({$account['account_type']}) - ID: {$account['id']}");
            }
        }
    }

    private function importStatistics(VkAdsImportService $importService, array $results): void
    {
        $this->info("\n=== Импорт статистики ===");

        $fromDate = $this->option('statistics-from')
            ? Carbon::parse($this->option('statistics-from'))
            : Carbon::now()->subDays(30);

        $toDate = $this->option('statistics-to')
            ? Carbon::parse($this->option('statistics-to'))
            : Carbon::now();

        $this->info("Период: {$fromDate->format('Y-m-d')} - {$toDate->format('Y-m-d')}");

        $progressBar = $this->output->createProgressBar(count($results['accounts']));
        $progressBar->start();

        foreach ($results['accounts'] as $accountData) {
            try {
                $account = \Modules\VkAds\app\Models\VkAdsAccount::find($accountData['id']);

                if ($account) {
                    $stats = $importService->importHistoricalStatistics($account, $fromDate, $toDate);
                    $this->line("\nИмпортировано {count($stats)} записей статистики для {$account->account_name}");
                }

            } catch (\Exception $e) {
                $this->line("\nОшибка импорта статистики для аккаунта {$accountData['id']}: " . $e->getMessage());
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();
    }

    private function simulateImport(int $agencyVkAccountId, string $accessToken): array
    {
        $this->info("Симуляция импорта для аккаунта {$agencyVkAccountId}");

        // Здесь можно добавить логику симуляции без записи в БД
        return [
            'accounts' => [
                ['id' => 'sim_1', 'account_name' => 'Simulation Agency', 'account_type' => 'agency'],
                ['id' => 'sim_2', 'account_name' => 'Simulation Client 1', 'account_type' => 'client'],
            ],
            'campaigns' => [
                ['id' => 'sim_camp_1', 'name' => 'Test Campaign 1'],
                ['id' => 'sim_camp_2', 'name' => 'Test Campaign 2'],
            ],
            'ad_groups' => [
                ['id' => 'sim_group_1', 'name' => 'Test Ad Group 1'],
            ],
            'creatives' => [
                ['id' => 'sim_creative_1', 'name' => 'Test Creative 1'],
            ],
            'ads' => [
                ['id' => 'sim_ad_1', 'name' => 'Test Ad 1'],
            ],
            'errors' => []
        ];
    }
}
