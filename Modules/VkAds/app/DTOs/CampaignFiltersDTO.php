<?php

namespace Modules\VkAds\app\DTOs;

use Illuminate\Http\Request;

class CampaignFiltersDTO
{
    public function __construct(
        public ?string $status = null,
        public ?string $campaignType = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public ?int $limit = null,
        public ?int $offset = null
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            status: $request->input('status'),
            campaignType: $request->input('campaign_type'),
            dateFrom: $request->input('date_from'),
            dateTo: $request->input('date_to'),
            limit: $request->input('limit') ? (int) $request->input('limit') : null,
            offset: $request->input('offset') ? (int) $request->input('offset') : null
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'campaign_type' => $this->campaignType,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'limit' => $this->limit,
            'offset' => $this->offset,
        ], fn ($value) => $value !== null);
    }
}
