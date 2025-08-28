<?php

namespace Modules\VkAds\app\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\VkAds\app\Models\VkAdsAccount;

class SyncCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ?VkAdsAccount $account,
        public array $syncResults,
        public int $duration // в секундах
    ) {}
}
