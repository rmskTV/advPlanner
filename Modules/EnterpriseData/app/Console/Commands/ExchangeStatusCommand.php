<?php

namespace Modules\EnterpriseData\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\EnterpriseData\app\Models\ExchangeFtpConnector;
use Modules\EnterpriseData\app\Models\ExchangeLog;
use Modules\EnterpriseData\app\Services\ExchangeConfigValidator;

class ExchangeStatusCommand extends Command
{
    protected $signature = 'exchange:status
                           {connector? : ID коннектора}
                           {--detailed : Подробная информация}
                           {--last=10 : Количество последних записей}';

    protected $description = 'Проверка статуса обмена данными';

    public function handle(ExchangeConfigValidator $validator): int
    {
        $connectorId = (int) $this->argument('connector');

        if ($connectorId) {
            return $this->showConnectorStatus($connectorId, $validator);
        } else {
            return $this->showAllConnectorsStatus($validator);
        }
    }

    private function showAllConnectorsStatus(ExchangeConfigValidator $validator): int
    {
        $connectors = ExchangeFtpConnector::all();

        if ($connectors->isEmpty()) {
            $this->warn('Коннекторы не настроены');

            return self::SUCCESS;
        }

        $this->info('=== СТАТУС ВСЕХ КОННЕКТОРОВ ===');

        $headers = ['ID', 'Название', 'Статус', 'Последний обмен', 'Ошибки'];
        $rows = [];

        foreach ($connectors as $connector) {
            $lastExchange = ExchangeLog::where('connector_id', $connector->id)
                ->latest('completed_at')
                ->first();

            $status = $this->getConnectorStatus($connector, $validator);
            $lastExchangeTime = $lastExchange ? $lastExchange->completed_at->diffForHumans() : 'Никогда';
            $errors = ExchangeLog::where('connector_id', $connector->id)
                ->where('status', 'failed')
                ->where('created_at', '>=', now()->subDay())
                ->count();

            $rows[] = [
                $connector->id,
                $connector->foreign_base_name,
                $status,
                $lastExchangeTime,
                $errors > 0 ? "<fg=red>{$errors}</>" : '<fg=green>0</>',
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    private function showConnectorStatus(int $connectorId, ExchangeConfigValidator $validator): int
    {
        $connector = ExchangeFtpConnector::find($connectorId);

        if (! $connector) {
            $this->error("Коннектор с ID {$connectorId} не найден");

            return self::FAILURE;
        }

        $this->info("=== СТАТУС КОННЕКТОРА #{$connector->id} ===");
        $this->line("Название: {$connector->foreign_base_name}");
        $this->line("FTP: {$connector->ftp_path}");
        $this->line("Формат: {$connector->exchange_format}");

        // Проверка конфигурации
        $validation = $validator->validateConnector($connector);
        if ($validation->isValid()) {
            $this->info('✓ Конфигурация корректна');
        } else {
            $this->error('✗ Ошибки конфигурации:');
            foreach ($validation->getErrors() as $error) {
                $this->line("  - {$error}");
            }
        }

        if (! empty($validation->getWarnings())) {
            $this->warn('⚠ Предупреждения:');
            foreach ($validation->getWarnings() as $warning) {
                $this->line("  - {$warning}");
            }
        }

        // Проверка подключения
        if ($validator->validateFtpConnection($connector)) {
            $this->info('✓ FTP подключение работает');
        } else {
            $this->error('✗ Проблемы с FTP подключением');
        }

        // Статистика обмена
        $this->showExchangeStatistics($connector);

        if ($this->option('detailed')) {
            $this->showDetailedLogs($connector);
        }

        return self::SUCCESS;
    }

    private function getConnectorStatus(ExchangeFtpConnector $connector, ExchangeConfigValidator $validator): string
    {
        $validation = $validator->validateConnector($connector);

        if (! $validation->isValid()) {
            return '<fg=red>Ошибка конфигурации</>';
        }

        if (! $validator->validateFtpConnection($connector)) {
            return '<fg=yellow>Проблемы подключения</>';
        }

        $recentErrors = ExchangeLog::where('connector_id', $connector->id)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recentErrors > 0) {
            return '<fg=yellow>Есть ошибки</>';
        }

        return '<fg=green>Работает</>';
    }

    private function showExchangeStatistics(ExchangeFtpConnector $connector): void
    {
        $this->line('');
        $this->info('=== СТАТИСТИКА ОБМЕНА ===');

        $stats = [
            'Сегодня' => now()->startOfDay(),
            'За неделю' => now()->startOfWeek(),
            'За месяц' => now()->startOfMonth(),
        ];

        foreach ($stats as $period => $since) {
            $successful = ExchangeLog::where('connector_id', $connector->id)
                ->where('status', 'completed')
                ->where('created_at', '>=', $since)
                ->count();

            $failed = ExchangeLog::where('connector_id', $connector->id)
                ->where('status', 'failed')
                ->where('created_at', '>=', $since)
                ->count();

            $objects = ExchangeLog::where('connector_id', $connector->id)
                ->where('status', 'completed')
                ->where('created_at', '>=', $since)
                ->sum('objects_count');

            $this->line("{$period}: {$successful} успешных, {$failed} ошибок, {$objects} объектов");
        }
    }

    private function showDetailedLogs(ExchangeFtpConnector $connector): void
    {
        $this->line('');
        $this->info('=== ПОСЛЕДНИЕ ОПЕРАЦИИ ===');

        $logs = ExchangeLog::where('connector_id', $connector->id)
            ->latest('created_at')
            ->limit($this->option('last'))
            ->get();

        if ($logs->isEmpty()) {
            $this->line('Операций не найдено');

            return;
        }

        $headers = ['Время', 'Направление', 'Статус', 'Объектов', 'Ошибки'];
        $rows = [];

        foreach ($logs as $log) {
            $status = $log->status === 'completed' ? '<fg=green>Успешно</>' : '<fg=red>Ошибка</>';
            $errors = ! empty($log->errors) ? implode('; ', array_slice($log->errors, 0, 2)) : '';

            $rows[] = [
                $log->created_at->format('d.m.Y H:i:s'),
                $log->direction,
                $status,
                $log->objects_count ?? 0,
                $errors,
            ];
        }

        $this->table($headers, $rows);
    }
}
