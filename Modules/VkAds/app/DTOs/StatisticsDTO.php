<?php

namespace Modules\VkAds\app\DTOs;

use Illuminate\Database\Eloquent\Collection;

class StatisticsDTO
{
    public function __construct(
        public array $data = [],
        public array $summary = [],
        public array $metrics = []
    ) {}

    public static function fromCollection(Collection $statistics, array $requestedMetrics): self
    {
        $data = [];
        $summary = [
            'total_impressions' => 0,
            'total_clicks' => 0,
            'total_spend' => 0,
            'total_conversions' => 0,
            'period_start' => null,
            'period_end' => null,
        ];

        foreach ($statistics as $stat) {
            $item = [
                'date' => $stat->stats_date->format('Y-m-d'),
                'impressions' => $stat->impressions,
                'clicks' => $stat->clicks,
                'spend' => (float) $stat->spend,
                'ctr' => (float) $stat->ctr,
                'cpc' => (float) $stat->cpc,
                'cpm' => (float) $stat->cpm,
            ];

            // Добавляем только запрошенные метрики
            $filteredItem = [];
            foreach ($requestedMetrics as $metric) {
                if (isset($item[$metric])) {
                    $filteredItem[$metric] = $item[$metric];
                }
            }

            $data[] = array_merge(['date' => $item['date']], $filteredItem);

            // Обновляем сводку
            $summary['total_impressions'] += $stat->impressions;
            $summary['total_clicks'] += $stat->clicks;
            $summary['total_spend'] += $stat->spend;

            if (! $summary['period_start'] || $stat->stats_date->lt($summary['period_start'])) {
                $summary['period_start'] = $stat->stats_date->format('Y-m-d');
            }
            if (! $summary['period_end'] || $stat->stats_date->gt($summary['period_end'])) {
                $summary['period_end'] = $stat->stats_date->format('Y-m-d');
            }
        }

        // Вычисляем общие метрики
        $summary['avg_ctr'] = $summary['total_impressions'] > 0
            ? ($summary['total_clicks'] / $summary['total_impressions']) * 100
            : 0;
        $summary['avg_cpc'] = $summary['total_clicks'] > 0
            ? $summary['total_spend'] / $summary['total_clicks']
            : 0;
        $summary['avg_cpm'] = $summary['total_impressions'] > 0
            ? ($summary['total_spend'] / $summary['total_impressions']) * 1000
            : 0;

        return new self($data, $summary, $requestedMetrics);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['data'] ?? [],
            $data['summary'] ?? [],
            $data['metrics'] ?? []
        );
    }

    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'summary' => $this->summary,
            'metrics' => $this->metrics,
        ];
    }

    // === ГЕТТЕРЫ ДЛЯ УДОБСТВА ===

    public function getTotalSpend(): float
    {
        return $this->summary['total_spend'] ?? 0;
    }

    public function getTotalImpressions(): int
    {
        return $this->summary['total_impressions'] ?? 0;
    }

    public function getTotalClicks(): int
    {
        return $this->summary['total_clicks'] ?? 0;
    }

    public function getTotalConversions(): int
    {
        return $this->summary['total_conversions'] ?? 0;
    }

    public function getAverageCTR(): float
    {
        return $this->summary['avg_ctr'] ?? 0;
    }

    public function getAverageCPC(): float
    {
        return $this->summary['avg_cpc'] ?? 0;
    }

    public function getAverageCPM(): float
    {
        return $this->summary['avg_cpm'] ?? 0;
    }
}
