<?php

namespace Modules\VkAds\app\Models;

use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Accounting\app\Models\CustomerOrderItem;

class VkAdsCampaign extends CatalogObject
{
    protected $fillable = [
        'vk_campaign_id', 'vk_ads_account_id', 'customer_order_item_id',
        'name', 'description', 'status', 'campaign_type',
        // ДОБАВЛЕНО: новые поля
        'autobidding_mode', 'budget_limit', 'budget_limit_day', 'max_price',
        'objective', 'priced_goal',
        'daily_budget', 'total_budget', 'start_date', 'end_date', 'last_sync_at'
    ];

    protected $casts = [
        'daily_budget' => 'decimal:2',
        'total_budget' => 'decimal:2',
        'budget_limit' => 'decimal:2',
        'budget_limit_day' => 'decimal:2',
        'max_price' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'last_sync_at' => 'datetime',
        'priced_goal' => 'array'
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(VkAdsAccount::class, 'vk_ads_account_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(CustomerOrderItem::class, 'customer_order_item_id');
    }

    public function adGroups(): HasMany
    {
        return $this->hasMany(VkAdsAdGroup::class, 'vk_ads_campaign_id');
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
        return ($this->budget_limit_day && $this->budget_limit_day > 0) ||
            ($this->daily_budget && $this->daily_budget > 0);
    }

    /**
     * Проверка наличия общего бюджета
     */
    public function hasTotalBudget(): bool
    {
        return ($this->budget_limit && $this->budget_limit > 0) ||
            ($this->total_budget && $this->total_budget > 0);
    }

    /**
     * Получить эффективный дневной бюджет
     */
    public function getEffectiveDailyBudget(): ?float
    {
        return $this->budget_limit_day ?? $this->daily_budget;
    }

    /**
     * Получить эффективный общий бюджет
     */
    public function getEffectiveTotalBudget(): ?float
    {
        return $this->budget_limit ?? $this->total_budget;
    }
}
