<?php

namespace Modules\VkAds\app\Models;

use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VkAdsAd extends CatalogObject
{
    protected $fillable = [
        'vk_ad_id', 'vk_ads_ad_group_id', 'vk_ads_creative_id',
        'name', 'status', 'headline', 'description', 'final_url'
    ];

    protected $casts = [
        'last_sync_at' => 'datetime',
    ];

    public function adGroup(): BelongsTo
    {
        return $this->belongsTo(VkAdsAdGroup::class, 'vk_ads_ad_group_id');
    }

}
