<?php

namespace Modules\VkAds\app\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ModerationCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public mixed $model, // VkAdsCreative, VkAdsAd, VkAdsCampaign
        public string $oldStatus,
        public string $newStatus
    ) {}
}
