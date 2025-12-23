<?php

namespace Modules\Accounting\app\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\Counterparty;
use Modules\Accounting\app\Models\CustomerOrder;

class FillCounterpartyResponsible extends Command
{
    protected $signature = 'counterparty:fill-responsible
                            {--chunk=100 : Number of counterparties to process at once}
                            {--dry-run : Run without saving changes}
                            {--id=* : Process specific counterparty IDs}';

    protected $description = 'Fill responsible_guid_1c for counterparties based on their recent orders';

    private int $processedCount = 0;

    private int $updatedCount = 0;

    private int $skippedCount = 0;

    private array $errors = [];

    public function handle(): int
    {
        $this->info('Starting to fill responsible for counterparties...');

        $chunkSize = (int) $this->option('chunk');
        $dryRun = $this->option('dry-run');
        $specificIds = $this->option('id');
        $isVerbose = $this->option('verbose'); // Используем встроенную опцию

        if ($dryRun) {
            $this->warn('🔸 DRY RUN MODE - No changes will be saved');
        }

        // Строим запрос
        $query = Counterparty::whereNull('responsible_guid_1c')
            ->where('deletion_mark', false);

        // Если указаны конкретные ID
        if (! empty($specificIds)) {
            $query->whereIn('id', $specificIds);
        }

        $totalCount = $query->count();

        if ($totalCount === 0) {
            $this->info('✓ No counterparties found without responsible');

            return self::SUCCESS;
        }

        $this->info("Found {$totalCount} counterparties to process");

        $progressBar = $this->output->createProgressBar($totalCount);
        $progressBar->start();

        // Обрабатываем контрагентов порциями
        $query->chunk($chunkSize, function ($counterparties) use ($progressBar, $dryRun, $isVerbose) {
            foreach ($counterparties as $counterparty) {
                try {
                    $this->processCounterparty($counterparty, $dryRun, $isVerbose);
                } catch (\Exception $e) {
                    $this->errors[] = [
                        'counterparty_id' => $counterparty->id,
                        'counterparty_name' => $counterparty->name,
                        'error' => $e->getMessage(),
                    ];

                    Log::error('Error processing counterparty', [
                        'counterparty_id' => $counterparty->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
                $progressBar->advance();
            }
        });

        $progressBar->finish();
        $this->newLine(2);

        // Выводим статистику
        $this->displayResults($dryRun);

        return empty($this->errors) ? self::SUCCESS : self::FAILURE;
    }

    private function processCounterparty(Counterparty $counterparty, bool $dryRun, bool $isVerbose): void
    {
        $this->processedCount++;

        if ($isVerbose) {
            $this->newLine();
            $this->line("Processing: {$counterparty->name} (ID: {$counterparty->id})");
        }

        // Получаем последние 4 заказа контрагента
        $orders = CustomerOrder::where('counterparty_guid_1c', $counterparty->guid_1c)
            ->whereNotNull('responsible_guid_1c')
            ->where('deletion_mark', false)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(4)
            ->get();

        // Если заказов нет - пропускаем
        if ($orders->isEmpty()) {
            $this->skippedCount++;
            if ($isVerbose) {
                $this->line('  ⊘ No orders found');
            }

            return;
        }

        if ($isVerbose) {
            $this->line("  Found {$orders->count()} orders");
        }

        // Определяем ответственного
        $selectedResponsible = $this->determineResponsible($orders, $isVerbose);

        if (! $selectedResponsible) {
            $this->skippedCount++;
            if ($isVerbose) {
                $this->line('  ⊘ Could not determine responsible');
            }

            return;
        }

        // Обновляем контрагента
        if (! $dryRun) {
            $counterparty->update([
                'responsible_guid_1c' => $selectedResponsible,
            ]);
        }

        $this->updatedCount++;

        if ($isVerbose) {
            $this->line("  ✓ Set responsible: {$selectedResponsible}");
        }
    }

    private function determineResponsible($orders, bool $isVerbose): ?string
    {
        // Подсчитываем частоту встречаемости responsible_guid_1c
        $responsibleData = [];

        foreach ($orders as $order) {
            $responsible = $order->responsible_guid_1c;

            if (! isset($responsibleData[$responsible])) {
                $responsibleData[$responsible] = [
                    'count' => 0,
                    'last_order_date' => $order->date,
                    'last_order_id' => $order->id,
                ];
            }

            $responsibleData[$responsible]['count']++;

            // Обновляем дату последнего заказа если текущий заказ новее
            if ($this->isOrderNewer($order, $responsibleData[$responsible])) {
                $responsibleData[$responsible]['last_order_date'] = $order->date;
                $responsibleData[$responsible]['last_order_id'] = $order->id;
            }
        }

        if ($isVerbose) {
            foreach ($responsibleData as $guid => $data) {
                $this->line("    {$guid}: {$data['count']} orders");
            }
        }

        // Находим максимальную частоту
        $maxCount = max(array_column($responsibleData, 'count'));

        // Отфильтровываем кандидатов с максимальной частотой
        $candidates = array_filter(
            $responsibleData,
            fn ($data) => $data['count'] === $maxCount
        );

        // Если несколько кандидатов - выбираем из самого свежего заказа
        if (count($candidates) > 1) {
            uasort($candidates, function ($a, $b) {
                // Сначала сравниваем даты
                if ($a['last_order_date'] && $b['last_order_date']) {
                    $dateCompare = $b['last_order_date'] <=> $a['last_order_date'];
                    if ($dateCompare !== 0) {
                        return $dateCompare;
                    }
                }

                // Если даты равны или одна null - сравниваем по ID
                return $b['last_order_id'] <=> $a['last_order_id'];
            });
        }

        return array_key_first($candidates);
    }

    private function isOrderNewer(CustomerOrder $order, array $currentData): bool
    {
        // Если текущая дата null - любой заказ новее
        if (! $currentData['last_order_date']) {
            return true;
        }

        // Если дата заказа null - он не новее
        if (! $order->date) {
            return false;
        }

        // Сравниваем даты
        if ($order->date > $currentData['last_order_date']) {
            return true;
        }

        // Если даты равны - сравниваем ID
        if ($order->date == $currentData['last_order_date'] &&
            $order->id > $currentData['last_order_id']) {
            return true;
        }

        return false;
    }

    private function displayResults(bool $dryRun): void
    {
        $this->info('═══════════════════════════════════════');
        $this->info('           Processing Results          ');
        $this->info('═══════════════════════════════════════');

        $this->table(
            ['Metric', 'Count'],
            [
                ['✓ Processed', $this->processedCount],
                ['✓ Updated', $this->updatedCount],
                ['⊘ Skipped (no orders)', $this->skippedCount],
                ['✗ Errors', count($this->errors)],
            ]
        );

        if ($dryRun && $this->updatedCount > 0) {
            $this->warn("⚠ DRY RUN: {$this->updatedCount} counterparties would be updated");
        }

        // Показываем ошибки если есть
        if (! empty($this->errors)) {
            $this->newLine();
            $this->error('Errors occurred during processing:');
            $this->table(
                ['ID', 'Name', 'Error'],
                array_map(fn ($err) => [
                    $err['counterparty_id'],
                    $err['counterparty_name'],
                    $err['error'],
                ], $this->errors)
            );
        }
    }
}
