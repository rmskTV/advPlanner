<?php

namespace Modules\VkAds\app\DTOs;

use Illuminate\Http\Request;

class BudgetUpdateDTO
{
    public function __construct(
        public ?float $dailyBudget = null,
        public ?float $totalBudget = null,
        public string $budgetType = 'daily'
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            dailyBudget: $request->input('daily_budget') ? (float) $request->input('daily_budget') : null,
            totalBudget: $request->input('total_budget') ? (float) $request->input('total_budget') : null,
            budgetType: $request->input('budget_type', 'daily')
        );
    }

    public function toArray(): array
    {
        return [
            'daily_budget' => $this->dailyBudget,
            'total_budget' => $this->totalBudget,
            'budget_type' => $this->budgetType,
        ];
    }
}
