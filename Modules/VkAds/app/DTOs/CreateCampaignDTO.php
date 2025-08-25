<?php

namespace Modules\VkAds\app\DTOs;

use Carbon\Carbon;
use Illuminate\Http\Request;

class CreateCampaignDTO
{
    public function __construct(
        public string $name,
        public ?string $description,
        public string $campaignType,
        public ?float $dailyBudget,
        public ?float $totalBudget,
        public string $budgetType,
        public Carbon $startDate,
        public ?Carbon $endDate = null,
        public array $targeting = []
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: $request->input('name'),
            description: $request->input('description'),
            campaignType: $request->input('campaign_type'),
            dailyBudget: $request->input('daily_budget') ? (float) $request->input('daily_budget') : null,
            totalBudget: $request->input('total_budget') ? (float) $request->input('total_budget') : null,
            budgetType: $request->input('budget_type', 'daily'),
            startDate: Carbon::parse($request->input('start_date')),
            endDate: $request->input('end_date') ? Carbon::parse($request->input('end_date')) : null,
            targeting: $request->input('targeting', [])
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            description: $data['description'] ?? null,
            campaignType: $data['campaign_type'],
            dailyBudget: $data['daily_budget'] ?? null,
            totalBudget: $data['total_budget'] ?? null,
            budgetType: $data['budget_type'] ?? 'daily',
            startDate: $data['start_date'] instanceof Carbon ? $data['start_date'] : Carbon::parse($data['start_date']),
            endDate: isset($data['end_date']) ? ($data['end_date'] instanceof Carbon ? $data['end_date'] : Carbon::parse($data['end_date'])) : null,
            targeting: $data['targeting'] ?? []
        );
    }

    public function toVkAdsFormat(): array
    {
        return [
            'name' => $this->name,
            'campaign_type' => $this->campaignType,
            'daily_budget' => $this->dailyBudget ? $this->dailyBudget * 100 : null, // VK принимает в копейках
            'total_budget' => $this->totalBudget ? $this->totalBudget * 100 : null,
            'start_date' => $this->startDate->format('Y-m-d'),
            'end_date' => $this->endDate?->format('Y-m-d'),
            'targeting' => $this->targeting,
        ];
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'campaign_type' => $this->campaignType,
            'daily_budget' => $this->dailyBudget,
            'total_budget' => $this->totalBudget,
            'budget_type' => $this->budgetType,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'targeting' => $this->targeting,
        ];
    }
}
