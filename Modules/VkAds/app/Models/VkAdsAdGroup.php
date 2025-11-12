<?php

namespace Modules\VkAds\app\Models;

use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Accounting\app\Models\CustomerOrderItem;

class VkAdsAdGroup extends CatalogObject
{
    protected $fillable = [
        'vk_ad_group_id', 'vk_ads_account_id', 'vk_ads_campaign_id', 'customer_order_item_id',
        'name', 'status', 'bid', 'targeting',
        // ДОБАВЛЕНО: новые поля
        'age_restrictions', 'autobidding_mode', 'budget_limit', 'budget_limit_day',
        'max_price', 'uniq_shows_limit', 'uniq_shows_period',
        'last_sync_at', 'vk_data',
    ];

    protected $casts = [
        'targeting' => 'array',
        'bid' => 'decimal:2',
        'budget_limit' => 'decimal:2',
        'budget_limit_day' => 'decimal:2',
        'max_price' => 'decimal:2',
        'uniq_shows_limit' => 'integer',
        'last_sync_at' => 'datetime',
        'vk_data' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(VkAdsAccount::class, 'vk_ads_account_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(VkAdsCampaign::class, 'vk_ads_campaign_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(CustomerOrderItem::class, 'customer_order_item_id');
    }

    public function ads(): HasMany
    {
        return $this->hasMany(VkAdsAd::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Проверка наличия дневного бюджета
     */
    public function hasDailyBudget(): bool
    {
        return $this->budget_limit_day && $this->budget_limit_day > 0;
    }

    /**
     * Проверка наличия общего бюджета
     */
    public function hasTotalBudget(): bool
    {
        return $this->budget_limit && $this->budget_limit > 0;
    }

    /**
     * Проверка наличия лимита уникальных показов
     */
    public function hasUniqueShowsLimit(): bool
    {
        return $this->uniq_shows_limit && $this->uniq_shows_limit > 0;
    }
}
