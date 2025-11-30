<?php

namespace Modules\Bitrix24\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\Bitrix24\app\Services\Bitrix24Service;

class CheckRequisitePresets extends Command
{
    protected $signature = 'b24:check-requisite-presets';
    protected $description = 'Check available requisite presets';

    public function handle(Bitrix24Service $b24Service)
    {
        // Получаем все шаблоны реквизитов
        $presets = $b24Service->call('crm.requisite.preset.list');

        $this->info("Available requisite presets:");
        foreach ($presets['result'] as $preset) {
            $this->info(sprintf(
                "ID: %s, Name: %s, Country: %s, Entity: %s, XML_ID: %s",
                $preset['ID'],
                $preset['NAME'],
                $preset['COUNTRY_ID'],
                $preset['ENTITY_TYPE_ID'],
                $preset['XML_ID'] ?? 'N/A'
            ));
        }

        $this->info("\n\nPreset details:");
        $this->info(print_r($presets['result'], true));
    }
}
