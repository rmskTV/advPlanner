<?php

namespace Modules\VkAds\app\Models;

use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VkAdsAd extends CatalogObject
{
    protected $fillable = [
        'vk_ad_id', 'vk_ads_ad_group_id', 'name', 'status',
        'content', 'delivery', 'issues', 'moderation_status', 'moderation_reasons',
        'textblocks', 'urls', 'ord_marker', 'created_at_vk', 'updated_at_vk',
        'last_sync_at'
    ];

    protected $casts = [
        'content' => 'array',
        'issues' => 'array',
        'moderation_reasons' => 'array',
        'textblocks' => 'array',
        'urls' => 'array',
        'created_at_vk' => 'datetime',
        'updated_at_vk' => 'datetime',
        'last_sync_at' => 'datetime',
    ];

    // ОБНОВЛЕНО: константы согласно фактическим значениям VK Ads API
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DELETED = 'deleted';
    public const STATUS_BLOCKED = 'blocked';

    public const DELIVERY_PENDING = 'pending';
    public const DELIVERY_DELIVERING = 'delivering';
    public const DELIVERY_NOT_DELIVERING = 'not_delivering';

    public const MODERATION_PENDING = 'pending';
    public const MODERATION_ALLOWED = 'allowed';
    public const MODERATION_BANNED = 'banned';

    public function adGroup(): BelongsTo
    {
        return $this->belongsTo(VkAdsAdGroup::class, 'vk_ads_ad_group_id');
    }

    // Методы проверки статусов
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isDelivering(): bool
    {
        return $this->delivery === self::DELIVERY_DELIVERING;
    }

    public function isModerationAllowed(): bool
    {
        return $this->moderation_status === self::MODERATION_ALLOWED;
    }

    public function canBeDisplayed(): bool
    {
        return $this->isActive() && $this->isDelivering() && $this->isModerationAllowed();
    }

    /**
     * Получить основную ссылку из urls
     */
    public function getPrimaryUrl(): ?string
    {
        if (!$this->urls || !is_array($this->urls)) {
            return null;
        }

        // ИСПРАВЛЕНО: обработка структуры URLs согласно VK API
        if (isset($this->urls['primary']['url'])) {
            return $this->urls['primary']['url'];
        }

        return $this->urls['primary'] ?? $this->urls[0] ?? null;
    }

    /**
     * Получить заголовок из textblocks
     */
    public function getHeadline(): ?string
    {
        if (!$this->textblocks || !is_array($this->textblocks)) {
            return null;
        }

        // ИСПРАВЛЕНО: обработка структуры textblocks согласно VK API
        if (isset($this->textblocks['title_30_additional']['text'])) {
            return $this->textblocks['title_30_additional']['text'];
        }

        if (isset($this->textblocks['title_40_vkads']['text'])) {
            return $this->textblocks['title_40_vkads']['text'];
        }

        return $this->textblocks['title'] ?? $this->textblocks['headline'] ?? null;
    }

    /**
     * Получить описание из textblocks
     */
    public function getDescription(): ?string
    {
        if (!$this->textblocks || !is_array($this->textblocks)) {
            return null;
        }

        if (isset($this->textblocks['text_220']['text'])) {
            return $this->textblocks['text_220']['text'];
        }

        if (isset($this->textblocks['text_90']['text'])) {
            return $this->textblocks['text_90']['text'];
        }

        return $this->textblocks['text'] ?? $this->textblocks['description'] ?? null;
    }
}
