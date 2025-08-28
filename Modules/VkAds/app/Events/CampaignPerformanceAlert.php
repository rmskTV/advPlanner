<?php

namespace Modules\VkAds\app\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\VkAds\app\Models\VkAdsCampaign;

class CampaignPerformanceAlert
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public VkAdsCampaign $campaign,
        public string $alertType, // 'low_ctr', 'high_cpc', 'low_impressions'
        public array $metrics,
        public array $thresholds
    ) {}
}
