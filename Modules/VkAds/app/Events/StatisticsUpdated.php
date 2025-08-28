<?php

namespace Modules\VkAds\app\Events;

use Modules\VkAds\app\Models\VkAdsStatistics;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StatisticsUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public VkAdsStatistics $statistics,
        public array $originalValues
    ) {}

    public function getChangedMetrics(): array
    {
        $changes = [];
        $metrics = ['impressions', 'clicks', 'spend', 'ctr', 'cpc', 'cpm'];

        foreach ($metrics as $metric) {
            $oldValue = $this->originalValues[$metric] ?? 0;
            $newValue = $this->statistics->$metric;

            if ($oldValue != $newValue) {
                $changes[$metric] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                    'change' => $newValue - $oldValue,
                    'change_percent' => $oldValue > 0 ? (($newValue - $oldValue) / $oldValue) * 100 : 0
                ];
            }
        }

        return $changes;
    }

    public function getSpendChange(): float
    {
        return $this->statistics->spend - ($this->originalValues['spend'] ?? 0);
    }

    public function hasSignificantChanges(): bool
    {
        $changes = $this->getChangedMetrics();

        foreach ($changes as $metric => $change) {
            if (abs($change['change_percent']) > 20) { // Изменение больше 20%
                return true;
            }
        }

        return false;
    }
}
