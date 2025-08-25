<?php

namespace Modules\VkAds\app\DTOs;

use Carbon\Carbon;
use Illuminate\Http\Request;

class GenerateActDTO
{
    public function __construct(
        public int $contractId,
        public Carbon $periodStart,
        public Carbon $periodEnd,
        public array $services = [],
        public ?float $totalAmount = null,
        public ?float $vatRate = null,
        public ?string $actNumber = null
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            contractId: (int) $request->input('contract_id'),
            periodStart: Carbon::parse($request->input('period_start')),
            periodEnd: Carbon::parse($request->input('period_end')),
            services: $request->input('services', []),
            totalAmount: $request->input('total_amount') ? (float) $request->input('total_amount') : null,
            vatRate: $request->input('vat_rate') ? (float) $request->input('vat_rate') : null,
            actNumber: $request->input('act_number')
        );
    }

    public function calculateVat(): float
    {
        if (! $this->totalAmount || ! $this->vatRate) {
            return 0;
        }

        return $this->totalAmount * ($this->vatRate / 100);
    }

    public function getServicesTotal(): float
    {
        return array_sum(array_column($this->services, 'amount'));
    }

    public function toArray(): array
    {
        return [
            'contract_id' => $this->contractId,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'services' => $this->services,
            'total_amount' => $this->totalAmount,
            'vat_rate' => $this->vatRate,
            'act_number' => $this->actNumber,
        ];
    }
}
