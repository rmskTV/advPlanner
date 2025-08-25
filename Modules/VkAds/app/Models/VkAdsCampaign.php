<?php

namespace Modules\VkAds\app\Models;

use App\Models\Document;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VkAdsCampaign extends Document
{
    protected $fillable = [
        'vk_campaign_id', 'vk_ads_account_id', 'name', 'description',
        'status', 'campaign_type', 'daily_budget', 'total_budget',
        'budget_type', 'start_date', 'end_date', 'vk_data',
    ];

    protected $casts = [
        'vk_data' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'daily_budget' => 'decimal:2',
        'total_budget' => 'decimal:2',
        'last_sync_at' => 'datetime',
    ];

    // === СВЯЗИ ===

    public function account(): BelongsTo
    {
        return $this->belongsTo(VkAdsAccount::class, 'vk_ads_account_id');
    }

    public function adGroups(): HasMany
    {
        return $this->hasMany(VkAdsAdGroup::class);
    }

    // === SCOPES ===

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePaused($query)
    {
        return $query->where('status', 'paused');
    }

    // === МЕТОДЫ ===

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getTotalSpend(): float
    {
        return $this->adGroups()
            ->with('statistics')
            ->get()
            ->flatMap->statistics
            ->sum('spend');
    }
}
