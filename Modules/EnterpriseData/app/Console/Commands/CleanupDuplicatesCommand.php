<?php

namespace Modules\EnterpriseData\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\Accounting\app\Models\CustomerOrder;
use Modules\Accounting\app\Models\Sale;

class CleanupDuplicatesCommand extends Command
{
    protected $signature = 'exchange:cleanup-duplicates
                           {--type=all : Тип документа (customer_orders|sales|all)}
                           {--order-id= : ID конкретного заказа}
                           {--dry-run : Показать что будет удалено без фактического удаления}';

    protected $description = 'Очистка дубликатов строк документов';

    public function handle(): int
    {
        $type = $this->option('type');
        $orderId = $this->option('order-id');
        $isDryRun = $this->option('dry-run');

        $this->info('=== ОЧИСТКА ДУБЛИКАТОВ ===');

        if ($isDryRun) {
            $this->warn('РЕЖИМ СИМУЛЯЦИИ - фактическое удаление не выполняется');
        }

        $totalRemoved = 0;

        if ($orderId) {
            // Очистка конкретного заказа
            $order = CustomerOrder::find($orderId);
            if (! $order) {
                $this->error("Заказ с ID {$orderId} не найден");

                return self::FAILURE;
            }

            $removed = $this->cleanupOrderDuplicates($order, $isDryRun);
            $totalRemoved += $removed;

        } else {
            // Массовая очистка
            if (in_array($type, ['customer_orders', 'all'])) {
                $this->info('Очистка дубликатов в заказах клиентов...');
                $orders = CustomerOrder::all();

                foreach ($orders as $order) {
                    $removed = $this->cleanupOrderDuplicates($order, $isDryRun);
                    $totalRemoved += $removed;
                }
            }

            if (in_array($type, ['sales', 'all'])) {
                $this->info('Очистка дубликатов в реализациях...');
                $sales = Sale::all();

                foreach ($sales as $sale) {
                    $removed = $this->cleanupSaleDuplicates($sale, $isDryRun);
                    $totalRemoved += $removed;
                }
            }
        }

        $this->info("Всего удалено дубликатов: {$totalRemoved}");

        return self::SUCCESS;
    }

    private function cleanupOrderDuplicates(CustomerOrder $order, bool $isDryRun): int
    {

        $itemsByLineNumber = $order->items()
            ->orderBy('line_number')
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('line_number');

        $duplicatesRemoved = 0;

        foreach ($itemsByLineNumber as $lineNumber => $items) {
            if ($items->count() > 1) {
                $this->line("  Заказ {$order->id}, строка {$lineNumber}: {$items->count()} дубликатов");

                $itemsToDelete = $items->slice(1);

                if (! $isDryRun) {
                    foreach ($itemsToDelete as $item) {
                        $item->delete();
                        $duplicatesRemoved++;
                    }
                } else {
                    $duplicatesRemoved += $itemsToDelete->count();
                }
            }
        }

        return $duplicatesRemoved;
    }

    private function cleanupSaleDuplicates(Sale $order, bool $isDryRun): int
    {
        $itemsByLineNumber = $order->items()
            ->orderBy('line_number')
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('line_number');

        $duplicatesRemoved = 0;

        foreach ($itemsByLineNumber as $lineNumber => $items) {
            if ($items->count() > 1) {
                $this->line("  Заказ {$order->id}, строка {$lineNumber}: {$items->count()} дубликатов");

                $itemsToDelete = $items->slice(1);

                if (! $isDryRun) {
                    foreach ($itemsToDelete as $item) {
                        $item->delete();
                        $duplicatesRemoved++;
                    }
                } else {
                    $duplicatesRemoved += $itemsToDelete->count();
                }
            }
        }

        return $duplicatesRemoved;
    }
}
