<?php

namespace Modules\VkAds\app\DTOs;

use Carbon\Carbon;
use Illuminate\Http\Request;

class StatisticsRequestDTO
{
    public function __construct(
        public Carbon $dateFrom,
        public Carbon $dateTo,
        public array $metrics = ['clicks', 'impressions', 'spend'],
        public string $groupBy = 'day',
        public array $filters = [],
        public ?int $limit = null
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            dateFrom: Carbon::parse($request->input('date_from')),
            dateTo: Carbon::parse($request->input('date_to')),
            metrics: $request->input('metrics', ['clicks', 'impressions', 'spend']),
            groupBy: $request->input('group_by', 'day'),
            filters: $request->input('filters', []),
            limit: $request->input('limit') ? (int) $request->input('limit') : null
        );
    }

    public function toVkAdsParams(): array
    {
        return [
            'date_from' => $this->dateFrom->format('Y-m-d'),
            'date_to' => $this->dateTo->format('Y-m-d'),
            'fields' => implode(',', $this->metrics),
            'group_by' => $this->groupBy,
            'limit' => $this->limit,
        ];
    }

    public function getCacheKey(): string
    {
        return 'vk_ads_stats_'.md5(serialize([
            $this->dateFrom->format('Y-m-d'),
            $this->dateTo->format('Y-m-d'),
            $this->metrics,
            $this->groupBy,
            $this->filters,
        ]));
    }

    public function getCacheTTL(): int
    {
        $daysDiff = $this->dateFrom->diffInDays($this->dateTo);

        // Чем больше период, тем дольше кэшируем
        if ($daysDiff > 30) {
            return 3600 * 24; // 24 часа
        } elseif ($daysDiff > 7) {
            return 3600 * 6; // 6 часов
        } else {
            return 3600; // 1 час
        }
    }
}
