<?php

namespace Modules\VkAds\app\Models;

use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Modules\Accounting\app\Models\CustomerOrderItem;
use Modules\Accounting\app\Models\Product;

class VkAdsAdGroup extends CatalogObject
{
    protected $fillable = [
        'vk_ad_group_id', 'vk_ads_campaign_id', 'customer_order_item_id',
        'name', 'status', 'bid', 'targeting', 'placements', 'vk_data',
    ];

    protected $casts = [
        'targeting' => 'array',
        'placements' => 'array',
        'vk_data' => 'array',
        'bid' => 'decimal:2',
        'last_sync_at' => 'datetime',
    ];

    // === СВЯЗИ ===

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(VkAdsCampaign::class, 'vk_ads_campaign_id');
    }

    /**
     * Группа объявлений привязана к строке заказа клиента
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(CustomerOrderItem::class, 'customer_order_item_id');
    }

    /**
     * Получить заказ через строку заказа
     */
    public function customerOrder(): HasOneThrough
    {
        return $this->hasOneThrough(
            \Modules\Accounting\app\Models\CustomerOrder::class,
            \Modules\Accounting\app\Models\CustomerOrderItem::class,
            'id',                    // Foreign key on customer_order_items table
            'id',                    // Foreign key on customer_orders table
            'customer_order_item_id', // Local key on ad_groups table
            'customer_order_id'      // Local key on customer_order_items table
        );
    }

    /**
     * Получить номенклатуру (услугу) через строку заказа
     */
    public function product(): HasOneThrough
    {
        return $this->hasOneThrough(
            Product::class,
            CustomerOrderItem::class,
            'id',                    // Foreign key on customer_order_items table
            'guid_1c',               // Foreign key on products table
            'customer_order_item_id', // Local key on ad_groups table
            'product_guid_1c'        // Local key on customer_order_items table
        );
    }

    public function statistics(): HasMany
    {
        return $this->hasMany(VkAdsStatistics::class);
    }

    // === SCOPES ===

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // === МЕТОДЫ ===

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getTotalSpend(): float
    {
        return $this->statistics()->sum('spend');
    }
}
