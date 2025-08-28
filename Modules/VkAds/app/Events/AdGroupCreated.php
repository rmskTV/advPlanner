<?php

namespace Modules\VkAds\app\Events;

use Modules\VkAds\app\Models\VkAdsAdGroup;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AdGroupCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public VkAdsAdGroup $adGroup
    ) {}
}
