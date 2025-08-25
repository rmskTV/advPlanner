<?php

namespace Modules\VkAds\app\Models;

use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Modules\Accounting\app\Models\Contract;
use Modules\Accounting\app\Models\Counterparty;
use Modules\Accounting\app\Models\Organization;

class VkAdsAccount extends CatalogObject
{
    protected $fillable = [
        'vk_account_id', 'account_name', 'account_type', 'account_status',
        'organization_id', 'contract_id', 'balance', 'currency',
        'access_roles', 'can_view_budget', 'sync_enabled',
    ];

    protected $casts = [
        'access_roles' => 'array',
        'can_view_budget' => 'boolean',
        'sync_enabled' => 'boolean',
        'balance' => 'decimal:2',
        'last_sync_at' => 'datetime',
    ];

    // === СВЯЗИ С ACCOUNTING МОДУЛЕМ ===

    /**
     * Агентский кабинет принадлежит организации
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Клиентский кабинет привязан к договору
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Получить контрагента через договор (для клиентских кабинетов) - УПРОЩЕНО
     */
    public function counterparty(): HasOneThrough
    {
        return $this->hasOneThrough(
            Counterparty::class,
            Contract::class,
            'id',              // Foreign key on contracts table
            'guid_1c',         // Foreign key on counterparties table
            'contract_id',     // Local key on vk_ads_accounts table
            'counterparty_guid_1c' // Local key on contracts table
        );
    }

    // === VK ADS СВЯЗИ ===

    public function campaigns(): HasMany
    {
        return $this->hasMany(VkAdsCampaign::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(VkAdsToken::class);
    }

    // === SCOPES ===

    public function scopeAgency($query)
    {
        return $query->where('account_type', 'agency');
    }

    public function scopeClient($query)
    {
        return $query->where('account_type', 'client');
    }

    public function scopeActive($query)
    {
        return $query->where('account_status', 'active');
    }

    // === МЕТОДЫ ===

    public function isAgency(): bool
    {
        return $this->account_type === 'agency';
    }

    public function isClient(): bool
    {
        return $this->account_type === 'client';
    }

    public function getValidToken(): ?VkAdsToken
    {
        return $this->tokens()
            ->where('is_active', true)
            ->where('expires_at', '>', now())
            ->first();
    }
}
