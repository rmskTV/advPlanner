<?php

namespace Modules\VkAds\app\DTOs;

use Illuminate\Http\Request;

class CreateAdGroupDTO
{
    public function __construct(
        public string $name,
        public int $customerOrderItemId,
        public ?float $bid = null,
        public array $targeting = [],
        public array $placements = []
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: $request->input('name'),
            customerOrderItemId: (int) $request->input('customer_order_item_id'),
            bid: $request->input('bid') ? (float) $request->input('bid') : null,
            targeting: $request->input('targeting', []),
            placements: $request->input('placements', [])
        );
    }

    public function toVkAdsFormat(): array
    {
        return [
            'name' => $this->name,
            'bid' => $this->bid ? $this->bid * 100 : null, // VK принимает в копейках
            'targeting' => $this->targeting,
            'placements' => $this->placements,
        ];
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'customer_order_item_id' => $this->customerOrderItemId,
            'bid' => $this->bid,
            'targeting' => $this->targeting,
            'placements' => $this->placements,
        ];
    }
}
