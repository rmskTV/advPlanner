<?php

namespace Modules\VkAds\app\Models;

use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VkAdsAd extends CatalogObject
{
    protected $fillable = [
        'vk_ad_id', 'vk_ads_ad_group_id', 'name', 'status',
        'headline', 'description', 'call_to_action', 'display_url', 'final_url',
        'is_instream', 'instream_position', 'skippable', 'skip_offset',
        'moderation_status', 'moderation_comment', 'moderated_at', 'vk_data',
    ];

    protected $casts = [
        'is_instream' => 'boolean',
        'skippable' => 'boolean',
        'vk_data' => 'array',
        'moderated_at' => 'datetime',
        'last_sync_at' => 'datetime',
    ];

    // === СВЯЗИ ===

    public function adGroup(): BelongsTo
    {
        return $this->belongsTo(VkAdsAdGroup::class, 'vk_ads_ad_group_id');
    }

    // ОБНОВЛЕНО: Many-to-Many связь с креативами
    public function creatives(): BelongsToMany
    {
        return $this->belongsToMany(VkAdsCreative::class, 'vk_ads_ad_creatives')
            ->withPivot(['role', 'is_active', 'priority'])
            ->withTimestamps();
    }

    public function statistics(): HasMany
    {
        return $this->hasMany(VkAdsAdStatistics::class);
    }

    // === МЕТОДЫ ДЛЯ РАБОТЫ С КРЕАТИВАМИ ===

    public function getPrimaryCreative(): ?VkAdsCreative
    {
        return $this->creatives()->wherePivot('role', 'primary')->first();
    }

    public function getCreativeForAspectRatio(string $aspectRatio): ?VkAdsCreative
    {
        $roleMap = [
            '16:9' => 'variant_16_9',
            '9:16' => 'variant_9_16',
            '1:1' => 'variant_1_1',
            '4:5' => 'variant_4_5',
        ];

        $role = $roleMap[$aspectRatio] ?? null;

        if (! $role) {
            return $this->getPrimaryCreative();
        }

        return $this->creatives()->wherePivot('role', $role)->first()
            ?: $this->getPrimaryCreative();
    }

    public function attachCreative(VkAdsCreative $creative, string $role = 'primary', int $priority = 0): void
    {
        $this->creatives()->attach($creative->id, [
            'role' => $role,
            'is_active' => true,
            'priority' => $priority,
        ]);
    }

    public function detachCreative(VkAdsCreative $creative): void
    {
        $this->creatives()->detach($creative->id);
    }

    public function hasCreativeForRole(string $role): bool
    {
        return $this->creatives()->wherePivot('role', $role)->exists();
    }

    public function getActiveCreatives(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->creatives()->wherePivot('is_active', true)->get();
    }
}
