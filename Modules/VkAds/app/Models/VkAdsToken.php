<?php

namespace Modules\VkAds\app\Models;

use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VkAdsToken extends CatalogObject
{
    protected $fillable = [
        'vk_ads_account_id', 'access_token', 'refresh_token',
        'expires_at', 'scopes', 'is_active',
    ];

    protected $casts = [
        'scopes' => 'array',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    protected $hidden = [
        'access_token', 'refresh_token',
    ];

    // === СВЯЗИ ===

    public function account(): BelongsTo
    {
        return $this->belongsTo(VkAdsAccount::class, 'vk_ads_account_id');
    }

    // === МЕТОДЫ ===

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return $this->is_active && ! $this->isExpired();
    }

    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }
}
