<?php

namespace Modules\VkAds\app\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\VkAds\app\Models\VkAdsCampaign;

class CampaignStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public VkAdsCampaign $campaign,
        public string $oldStatus,
        public string $newStatus
    ) {}
}
