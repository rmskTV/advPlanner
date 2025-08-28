<?php

namespace Modules\VkAds\app\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\VkAds\app\Jobs\GenerateMonthlyActs;

class GenerateActsCommand extends Command
{
    protected $signature = 'vk-ads:generate-acts
                           {--month= : Месяц для генерации актов (1-12)}
                           {--year= : Год для генерации актов}';

    protected $description = 'Генерация актов выполненных работ по VK Ads';

    public function handle(): int
    {
        $month = $this->option('month') ?? Carbon::now()->subMonth()->month;
        $year = $this->option('year') ?? Carbon::now()->subMonth()->year;

        $this->info("Запуск генерации актов за {$month}/{$year}");

        GenerateMonthlyActs::dispatch($month, $year);

        $this->info('Задача генерации актов добавлена в очередь');

        return self::SUCCESS;
    }
}
