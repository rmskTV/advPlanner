<?php

namespace Modules\VkAds\app\Models;

use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\Contract;
use Modules\Accounting\app\Models\Organization;

class VkAdsAccount extends CatalogObject
{
    protected $fillable = [
        'vk_account_id', 'vk_user_id', 'vk_username',
        'account_name', 'account_type', 'account_status',
        'organization_id', 'contract_id', 'balance', 'currency', 'last_sync_at'
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'last_sync_at' => 'datetime',
    ];

    // === СВЯЗИ ===
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(VkAdsCampaign::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(VkAdsToken::class);
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

    public function getValidTokenProd(): ?VkAdsToken
    {
        return $this->tokens()
            ->where('is_active', true)
            ->where('expires_at', '>', now())
            ->first();
    }

    public function getValidToken(): ?VkAdsToken
    {
        Log::info("Looking for valid token", [
            'account_id' => $this->id,
            'account_type' => $this->account_type
        ]);

        $token = $this->tokens()
            ->where('is_active', true)
            ->where('expires_at', '>', now())
            ->first();

        if ($token) {
            Log::info("Found valid token", [
                'token_id' => $token->id,
                'token_type' => $token->token_type,
                'expires_at' => $token->expires_at,
                'minutes_until_expiry' => now()->diffInMinutes($token->expires_at)
            ]);
        } else {
            // ДОБАВЛЕНО: логирование всех токенов для диагностики
            $allTokens = $this->tokens()->get();
            Log::info("No valid token found", [
                'account_id' => $this->id,
                'total_tokens' => $allTokens->count(),
                'tokens_info' => $allTokens->map(function($t) {
                    return [
                        'id' => $t->id,
                        'type' => $t->token_type,
                        'active' => $t->is_active,
                        'expires_at' => $t->expires_at,
                        'is_expired' => $t->expires_at < now()
                    ];
                })
            ]);
        }

        return $token;
    }
}
