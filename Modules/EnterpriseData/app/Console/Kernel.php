<?php

namespace Modules\EnterpriseData\app\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Автоматический обмен каждые 15 минут
        $schedule->command('exchange:process all')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10) // Предотвращение наложения задач
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/exchange-cron.log'))
            ->emailOutputOnFailure(config('enterprisedata.monitoring.alert_email'));

        // Очистка старых логов раз в день
        $schedule->command('exchange:cleanup-logs')
            ->daily()
            ->at('02:00')
            ->runInBackground();

        // Проверка статуса коннекторов каждый час
        $schedule->command('exchange:status')
            ->hourly()
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/exchange-status.log'));
    }
}
