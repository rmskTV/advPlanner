<?php

namespace Modules\VkAds\app\Models;

use App\Models\Registry;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Modules\Accounting\app\Models\SaleItem;

class VkAdsStatistics extends Registry
{
    protected $fillable = [
        'vk_ads_ad_group_id', 'sale_item_id', 'stats_date', 'period_type',
        'impressions', 'clicks', 'spend', 'ctr', 'cpc', 'cpm',
    ];

    protected $casts = [
        'stats_date' => 'date',
        'spend' => 'decimal:2',
        'ctr' => 'decimal:4',
        'cpc' => 'decimal:2',
        'cpm' => 'decimal:2',
    ];

    // === СВЯЗИ ===

    public function adGroup(): BelongsTo
    {
        return $this->belongsTo(VkAdsAdGroup::class, 'vk_ads_ad_group_id');
    }

    /**
     * Статистика может быть привязана к строке реализации (для актов)
     */
    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    /**
     * Получить реализацию через строку реализации
     */
    public function sale(): HasOneThrough
    {
        return $this->hasOneThrough(
            \Modules\Accounting\app\Models\Sale::class,
            \Modules\Accounting\app\Models\SaleItem::class,
            'id',          // Foreign key on sale_items table
            'id',          // Foreign key on sales table
            'sale_item_id', // Local key on statistics table
            'sale_id'      // Local key on sale_items table
        );
    }

    // === МЕТОДЫ ===

    public function calculateCTR(): float
    {
        return $this->impressions > 0 ? ($this->clicks / $this->impressions) * 100 : 0;
    }

    public function calculateCPC(): float
    {
        return $this->clicks > 0 ? $this->spend / $this->clicks : 0;
    }

    public function calculateCPM(): float
    {
        return $this->impressions > 0 ? ($this->spend / $this->impressions) * 1000 : 0;
    }
}
