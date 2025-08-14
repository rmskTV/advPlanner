<?php

namespace Modules\EnterpriseData\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\EnterpriseData\app\Models\ExchangeFtpConnector;
use Modules\EnterpriseData\app\Services\ExchangeOrchestrator;

class ProcessExchangeCommand extends Command
{
    protected $signature = 'exchange:process
                           {connector? : ID коннектора или "all" для всех}
                           {--direction=both : Направление обмена (incoming|outgoing|both)}
                           {--force : Принудительный запуск игнорируя блокировки}
                           {--dry-run : Режим тестирования без реальных изменений}
                           {--timeout=300 : Таймаут выполнения в секундах}';

    protected $description = 'Обработка обмена данными с 1С через FTP';

    public function handle(ExchangeOrchestrator $orchestrator): int
    {
        $this->info('Запуск обработки обмена данными...');

        try {
            // Установка таймаута
            set_time_limit($this->option('timeout'));

            // Получение коннекторов
            $connectors = $this->getConnectors();

            if ($connectors->isEmpty()) {
                $this->error('Коннекторы не найдены');

                return self::FAILURE;
            }

            $totalResults = [];
            $progressBar = $this->output->createProgressBar($connectors->count());
            $progressBar->start();

            foreach ($connectors as $connector) {
                try {
                    $this->processConnector($connector, $orchestrator, $totalResults);
                } catch (\Exception $e) {
                    $this->error("Ошибка обработки коннектора {$connector->id}: ".$e->getMessage());
                } finally {
                    $progressBar->advance();
                }
            }

            $progressBar->finish();
            $this->newLine();

            // Вывод итоговой статистики
            $this->displayResults($totalResults);

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Критическая ошибка: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function getConnectors()
    {
        $connectorId = $this->argument('connector');

        if (! $connectorId || $connectorId === 'all') {
            return ExchangeFtpConnector::all();
        }

        if (is_numeric($connectorId)) {
            $connector = ExchangeFtpConnector::find($connectorId);

            return $connector ? collect([$connector]) : collect();
        }

        $this->error('Некорректный ID коннектора');

        return collect();
    }

    private function processConnector(
        ExchangeFtpConnector $connector,
        ExchangeOrchestrator $orchestrator,
        array &$totalResults
    ): void {
        $this->info("Обработка коннектора: {$connector->foreign_base_name} (ID: {$connector->id})");

        $direction = $this->option('direction');
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('РЕЖИМ ТЕСТИРОВАНИЯ - изменения не будут сохранены');
        }

        // Входящий обмен
        if (in_array($direction, ['incoming', 'both'])) {
            $this->line('  → Обработка входящих сообщений...');
            $result = $orchestrator->processIncomingExchange($connector);
            $this->displayExchangeResult('Входящий', $result, $isDryRun);
            $totalResults['incoming'][] = $result;
        }

        // Исходящий обмен
        if (in_array($direction, ['outgoing', 'both'])) {
            $this->line('  → Обработка исходящих сообщений...');
            $result = $orchestrator->processOutgoingExchange($connector);
            $this->displayExchangeResult('Исходящий', $result, $isDryRun);
            $totalResults['outgoing'][] = $result;
        }
    }

    private function displayExchangeResult(string $direction, $result, bool $isDryRun = false): void
    {
        $prefix = $isDryRun ? '[DRY RUN] ' : '';

        if ($result->success) {
            $this->info("    ✓ {$prefix}{$direction} обмен: {$result->processedMessages} сообщений, {$result->processedObjects} объектов");
        } else {
            $this->error("    ✗ {$prefix}{$direction} обмен: ".implode(', ', $result->errors));
        }

        if (! empty($result->warnings)) {
            $this->warn('    ⚠ Предупреждения: '.implode(', ', $result->warnings));
        }
    }

    private function displayResults(array $totalResults): void
    {
        $this->info('=== ИТОГОВАЯ СТАТИСТИКА ===');

        foreach (['incoming' => 'Входящий', 'outgoing' => 'Исходящий'] as $type => $label) {
            if (! isset($totalResults[$type])) {
                continue;
            }

            $results = $totalResults[$type];
            $totalMessages = array_sum(array_map(fn ($r) => $r->processedMessages, $results));
            $totalObjects = array_sum(array_map(fn ($r) => $r->processedObjects, $results));
            $errors = array_sum(array_map(fn ($r) => count($r->errors), $results));

            $this->line("{$label} обмен:");
            $this->line("  Сообщений: {$totalMessages}");
            $this->line("  Объектов: {$totalObjects}");
            $this->line("  Ошибок: {$errors}");
        }
    }
}
