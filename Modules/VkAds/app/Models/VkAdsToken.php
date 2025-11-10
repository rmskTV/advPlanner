<?php

namespace Modules\VkAds\app\Models;

use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VkAdsToken extends CatalogObject
{
    protected $fillable = [
        'vk_ads_account_id', 'access_token', 'token_type', 'refresh_token', 'scopes',
        'expires_at', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    protected $hidden = [
        'access_token',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(VkAdsAccount::class, 'vk_ads_account_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return $this->is_active && ! $this->isExpired();
    }
}
