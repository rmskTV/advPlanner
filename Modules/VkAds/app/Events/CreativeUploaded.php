<?php

namespace Modules\VkAds\app\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\VkAds\app\Models\VkAdsCreative;

class CreativeUploaded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public VkAdsCreative $creative
    ) {}
}
