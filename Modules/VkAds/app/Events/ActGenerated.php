<?php

namespace Modules\VkAds\app\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Accounting\app\Models\Contract;
use Modules\Accounting\app\Models\Sale;

class ActGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Sale $sale,
        public Contract $contract,
        public array $campaignStats
    ) {}
}
