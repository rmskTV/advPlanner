<?php

namespace Modules\VkAds\app\Events;

use Modules\VkAds\app\Models\VkAdsAccount;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AccountConnected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public VkAdsAccount $account
    ) {}
}
