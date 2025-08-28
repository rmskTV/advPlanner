<?php

namespace Modules\VkAds\app\DTOs;

use Carbon\Carbon;
use Illuminate\Http\Request;

class UpdateCampaignDTO
{
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
        public ?float $dailyBudget = null,
        public ?float $totalBudget = null,
        public ?string $budgetType = null,
        public ?Carbon $endDate = null,
        public ?array $targeting = null
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: $request->input('name'),
            description: $request->input('description'),
            dailyBudget: $request->input('daily_budget') ? (float) $request->input('daily_budget') : null,
            totalBudget: $request->input('total_budget') ? (float) $request->input('total_budget') : null,
            budgetType: $request->input('budget_type'),
            endDate: $request->input('end_date') ? Carbon::parse($request->input('end_date')) : null,
            targeting: $request->input('targeting')
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'description' => $this->description,
            'daily_budget' => $this->dailyBudget,
            'total_budget' => $this->totalBudget,
            'budget_type' => $this->budgetType,
            'end_date' => $this->endDate,
            'targeting' => $this->targeting,
        ], fn ($value) => $value !== null);
    }
}
