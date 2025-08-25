<?php

namespace Modules\EnterpriseData\app\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\EnterpriseData\app\Models\ExchangeLog;

class CleanupExchangeLogsCommand extends Command
{
    protected $signature = 'exchange:cleanup-logs
                           {--days=30 : Количество дней для хранения логов}
                           {--dry-run : Показать что будет удалено без реального удаления}
                           {--force : Принудительная очистка без подтверждения}';

    protected $description = 'Очистка старых логов обмена данными';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoffDate = now()->subDays($days);

        $this->info("Очистка логов старше {$days} дней (до {$cutoffDate->format('d.m.Y H:i:s')})");

        // Подсчет записей для удаления
        $exchangeLogsCount = ExchangeLog::where('created_at', '<', $cutoffDate)->count();

        $this->line('К удалению:');
        $this->line("  - Логов обмена: {$exchangeLogsCount}");
        if ($this->option('dry-run')) {
            $this->warn('РЕЖИМ ТЕСТИРОВАНИЯ - записи не будут удалены');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Продолжить удаление?')) {
            $this->info('Операция отменена');

            return self::SUCCESS;
        }

        // Удаление в транзакции
        DB::transaction(function () use ($cutoffDate) {

            // Затем основные логи
            $deletedExchangeLogs = ExchangeLog::where('created_at', '<', $cutoffDate)->delete();

            $this->info('Удалено:');
            $this->line("  - Логов обмена: {$deletedExchangeLogs}");
        });

        return self::SUCCESS;
    }
}
