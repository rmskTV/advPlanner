<?php

namespace Modules\Accounting\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccount extends Model
{
    protected $table = 'bank_accounts';

    protected $fillable = [
        'guid_1c',
        'counterparty_id',
        'counterparty_guid_1c',
        'currency_id',
        'currency_guid_1c',
        'currency_code',
        'account_number',
        'name',
        'bank_guid_1c',
        'bank_name',
        'bank_bik',
        'bank_correspondent_account',
        'bank_swift',
        'account_type',
        'print_month_in_words',
        'print_amount_without_kopecks',
        'deletion_mark',
        'is_active',
        'last_sync_at',
    ];

    protected $casts = [
        'print_month_in_words' => 'boolean',
        'print_amount_without_kopecks' => 'boolean',
        'deletion_mark' => 'boolean',
        'is_active' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    /**
     * Связь с контрагентом (владельцем счета)
     */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    /**
     * Связь с валютой
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Поиск по GUID 1С
     */
    public static function findByGuid1C(string $guid): ?self
    {
        return static::where('guid_1c', $guid)->first();
    }

    /**
     * Поиск по номеру счета
     */
    public static function findByAccountNumber(string $accountNumber): ?self
    {
        // Очищаем номер от пробелов и дефисов
        $cleaned = preg_replace('/[\s\-]/', '', $accountNumber);

        return static::where('account_number', $cleaned)->first();
    }

    /**
     * Получить форматированный номер счета (xxxx xxxx xxxx xxxx xxxx)
     */
    public function getFormattedAccountNumber(): string
    {
        if (empty($this->account_number)) {
            return '';
        }

        return implode(' ', str_split($this->account_number, 4));
    }

    /**
     * Получить полную информацию о банке
     */
    public function getBankFullInfo(): string
    {
        $parts = array_filter([
            $this->bank_name,
            $this->bank_bik ? "БИК: {$this->bank_bik}" : null,
            $this->bank_correspondent_account ? "К/с: {$this->bank_correspondent_account}" : null,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Проверка валидности БИК
     */
    public function isValidBIK(): bool
    {
        if (empty($this->bank_bik)) {
            return false;
        }

        return preg_match('/^\d{9}$/', $this->bank_bik) === 1;
    }

    /**
     * Проверка валидности номера счета
     */
    public function isValidAccountNumber(): bool
    {
        if (empty($this->account_number)) {
            return false;
        }

        return preg_match('/^\d{20}$/', $this->account_number) === 1;
    }

    /**
     * Scope: только активные счета
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('deletion_mark', false);
    }

    /**
     * Scope: счета определенного контрагента
     */
    public function scopeForCounterparty($query, int $counterpartyId)
    {
        return $query->where('counterparty_id', $counterpartyId);
    }

    /**
     * Scope: расчетные счета
     */
    public function scopeSettlement($query)
    {
        return $query->where('account_type', 'Расчетный');
    }

    /**
     * Получить краткое описание счета
     */
    public function getShortDescription(): string
    {
        return sprintf(
            '%s (%s)',
            $this->getFormattedAccountNumber(),
            $this->bank_name ?? 'Банк не указан'
        );
    }
}
