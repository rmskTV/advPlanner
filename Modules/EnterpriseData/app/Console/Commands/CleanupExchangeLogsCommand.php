<?php

namespace Modules\EnterpriseData\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\EnterpriseData\app\Models\ExchangeLog;
use Modules\EnterpriseData\app\Models\ObjectChangeLog;

class CleanupExchangeLogsCommand extends Command
{
    protected $signature = 'exchange:cleanup-logs
                           {--days=30 : Количество дней для хранения логов}
                           {--dry-run : Показать что будет удалено без реального удаления}
                           {--force : Принудительная очистка без подтверждения}';

    protected $description = 'Очистка старых логов обмена данными';

    public function handle(): int
    {
        $days = $this->option('days');
        $cutoffDate = now()->subDays($days);

        $this->info("Очистка логов старше {$days} дней (до {$cutoffDate->format('d.m.Y H:i:s')})");

        // Подсчет записей для удаления
        $exchangeLogsCount = ExchangeLog::where('created_at', '<', $cutoffDate)->count();
        $changeLogsCount = ObjectChangeLog::where('created_at', '<', $cutoffDate)->count();

        if ($exchangeLogsCount === 0 && $changeLogsCount === 0) {
            $this->info('Нет логов для удаления');

            return self::SUCCESS;
        }

        $this->line('К удалению:');
        $this->line("  - Логов обмена: {$exchangeLogsCount}");
        $this->line("  - Логов изменений объектов: {$changeLogsCount}");

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
            // Сначала удаляем связанные записи
            $deletedChangeLogs = ObjectChangeLog::where('created_at', '<', $cutoffDate)->delete();

            // Затем основные логи
            $deletedExchangeLogs = ExchangeLog::where('created_at', '<', $cutoffDate)->delete();

            $this->info('Удалено:');
            $this->line("  - Логов обмена: {$deletedExchangeLogs}");
            $this->line("  - Логов изменений объектов: {$deletedChangeLogs}");
        });

        return self::SUCCESS;
    }
}
