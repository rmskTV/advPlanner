<?php

namespace Modules\EnterpriseData\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\EnterpriseData\app\Models\ExchangeFtpConnector;
use Modules\EnterpriseData\app\Services\ExchangeFtpConnectorService;

class TestFtpConnectionCommand extends Command
{
    protected $signature = 'exchange:test-connection {connector : ID коннектора}';

    protected $description = 'Тестирование FTP подключения';

    public function handle(ExchangeFtpConnectorService $ftpService): int
    {
        $connectorId = $this->argument('connector');
        $connector = ExchangeFtpConnector::find($connectorId);

        if (! $connector) {
            $this->error("Коннектор с ID {$connectorId} не найден");

            return self::FAILURE;
        }

        $this->info("Тестирование подключения для коннектора: {$connector->foreign_base_name}");
        $this->line("FTP: {$connector->ftp_path}:{$connector->ftp_port}");
        $this->line("Логин: {$connector->ftp_login}");
        $this->line('Пассивный режим: '.($connector->ftp_passive_mode ? 'Да' : 'Нет'));

        $result = $ftpService->testConnectionDetailed($connector);

        $this->line('');
        $this->info('=== РЕЗУЛЬТАТЫ ТЕСТИРОВАНИЯ ===');

        foreach ($result['steps'] as $step) {
            $status = $step['success'] ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->line("{$status} {$step['step']}");

            if (isset($step['error'])) {
                $this->line("    Ошибка: {$step['error']}");
            }

            if (isset($step['items_count'])) {
                $this->line("    Найдено элементов: {$step['items_count']}");
            }

            if (isset($step['path'])) {
                $this->line("    Путь: {$step['path']}");
            }
        }

        if ($result['success']) {
            $this->info('Подключение успешно!');

            return self::SUCCESS;
        } else {
            $this->error('Подключение не удалось: '.($result['error'] ?? 'Unknown error'));

            return self::FAILURE;
        }
    }
}
