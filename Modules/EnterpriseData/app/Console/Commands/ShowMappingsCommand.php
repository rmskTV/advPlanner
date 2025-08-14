<?php

namespace Modules\EnterpriseData\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\EnterpriseData\app\Registry\ObjectMappingRegistry;

class ShowMappingsCommand extends Command
{
    protected $signature = 'exchange:mappings';

    protected $description = 'Показать зарегистрированные маппинги';

    public function handle(ObjectMappingRegistry $registry): int
    {
        $this->info('=== ЗАРЕГИСТРИРОВАННЫЕ МАППИНГИ ===');

        $mappings = $registry->getAllMappings();

        if (empty($mappings)) {
            $this->warn('Маппинги не зарегистрированы!');

            return self::FAILURE;
        }

        $this->line('Всего маппингов: '.count($mappings));

        foreach ($mappings as $objectType => $mapping) {
            $this->line("✓ {$objectType} → ".$mapping->getModelClass());
        }

        $stats = $registry->getMappingStatistics();

        $this->line('');
        $this->info('=== СТАТИСТИКА ===');
        $this->line("Приоритетных маппингов: {$stats['priority_mappings']}");
        $this->line("Покрытие приоритетных типов: {$stats['priority_completion_rate']}%");

        if (! empty($stats['missing_priority_types'])) {
            $this->line('');
            $this->warn('❌ ОТСУТСТВУЮЩИЕ ПРИОРИТЕТНЫЕ МАППИНГИ:');
            foreach ($stats['missing_priority_types'] as $type) {
                $this->line("  - {$type}");
            }
        }

        return self::SUCCESS;
    }
}
