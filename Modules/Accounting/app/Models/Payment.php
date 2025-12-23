<?php

namespace Modules\Accounting\app\Models;

use App\Models\Document;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель платежа (расчеты с контрагентами)
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $guid_1c
 * @property string|null $number
 * @property Carbon|null $date
 * @property int|null $organization_id
 * @property string|null $organization_guid_1c
 * @property int|null $counterparty_id
 * @property string|null $counterparty_guid_1c
 * @property string $payment_type
 * @property float|null $amount
 * @property int|null $currency_id
 * @property string|null $currency_guid_1c
 * @property Carbon|null $statement_date
 * @property string|null $payment_purpose
 * @property Carbon|null $incoming_document_date
 * @property string|null $incoming_document_number
 * @property int|null $organization_bank_account_id
 * @property string|null $organization_bank_account_guid_1c
 * @property int|null $counterparty_bank_account_id
 * @property string|null $counterparty_bank_account_guid_1c
 * @property string|null $responsible_guid_1c
 * @property string|null $responsible_name
 * @property bool $deletion_mark
 * @property Carbon|null $last_sync_at
 * @property Organization|null $organization
 * @property Counterparty|null $counterparty
 * @property Currency|null $currency
 * @property BankAccount|null $organizationBankAccount
 * @property BankAccount|null $counterpartyBankAccount
 */
class Payment extends Document
{
    protected $table = 'payments';

    protected $fillable = [
        'guid_1c',
        'number',
        'date',
        'organization_id',
        'organization_guid_1c',
        'counterparty_id',
        'counterparty_guid_1c',
        'payment_type',
        'amount',
        'currency_id',
        'currency_guid_1c',
        'statement_date',
        'payment_purpose',
        'incoming_document_date',
        'incoming_document_number',
        'organization_bank_account_id',
        'organization_bank_account_guid_1c',
        'counterparty_bank_account_id',
        'counterparty_bank_account_guid_1c',
        'responsible_guid_1c',
        'responsible_name',
        'deletion_mark',
        'last_sync_at',
    ];

    protected $casts = [
        'date' => 'datetime',
        'statement_date' => 'date',
        'incoming_document_date' => 'date',
        'amount' => 'decimal:2',
        'deletion_mark' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    protected $appends = [
        'full_name',
        'payment_type_label',
    ];

    // Константы для типов платежей
    public const TYPE_INCOMING = 'incoming'; // СПокупателем - входящий платеж

    public const TYPE_OUTGOING = 'outgoing'; // СПоставщиком - исходящий платеж

    public static function getPaymentTypes(): array
    {
        return [
            self::TYPE_INCOMING => 'Входящий платеж',
            self::TYPE_OUTGOING => 'Исходящий платеж',
        ];
    }

    /**
     * Связь с организацией
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Связь с контрагентом
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
     * Связь с банковским счетом организации
     */
    public function organizationBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'organization_bank_account_id');
    }

    /**
     * Связь с банковским счетом контрагента
     */
    public function counterpartyBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'counterparty_bank_account_id');
    }

    /**
     * Связь со строками расшифровки платежа
     */
    public function items(): HasMany
    {
        return $this->hasMany(PaymentItem::class)->orderBy('line_number');
    }

    /**
     * Поиск платежа по GUID 1С
     */
    public static function findByGuid1C(string $guid): ?self
    {
        return self::where('guid_1c', $guid)->first();
    }

    /**
     * Поиск платежа по номеру и дате
     */
    public static function findByNumberAndDate(string $number, $date): ?self
    {
        return self::where('number', $number)
            ->whereDate('date', $date)
            ->first();
    }

    /**
     * Accessor для полного наименования
     */
    public function getFullNameAttribute(): string
    {
        $typeLabel = $this->getPaymentTypeLabel();

        return "{$typeLabel} № {$this->number} от {$this->date->format('d.m.Y')}";
    }

    /**
     * Accessor для метки типа платежа
     */
    public function getPaymentTypeLabelAttribute(): string
    {
        return $this->getPaymentTypeLabel();
    }

    /**
     * Получение метки типа платежа
     */
    public function getPaymentTypeLabel(): string
    {
        return self::getPaymentTypes()[$this->payment_type] ?? $this->payment_type;
    }

    /**
     * Проверка типа платежа
     */
    public function isIncoming(): bool
    {
        return $this->payment_type === self::TYPE_INCOMING;
    }

    public function isOutgoing(): bool
    {
        return $this->payment_type === self::TYPE_OUTGOING;
    }

    /**
     * Обновление времени синхронизации
     */
    public function touchSync(): void
    {
        $this->update(['last_sync_at' => now()]);
    }

    /**
     * Scope для входящих платежей
     */
    public function scopeIncoming($query)
    {
        return $query->where('payment_type', self::TYPE_INCOMING);
    }

    /**
     * Scope для исходящих платежей
     */
    public function scopeOutgoing($query)
    {
        return $query->where('payment_type', self::TYPE_OUTGOING);
    }

    /**
     * Scope для платежей организации
     */
    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope для платежей контрагента
     */
    public function scopeForCounterparty($query, int $counterpartyId)
    {
        return $query->where('counterparty_id', $counterpartyId);
    }

    /**
     * Scope для платежей за период
     */
    public function scopeForPeriod($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Получение общей суммы по строкам расшифровки
     */
    public function getCalculatedAmount(): float
    {
        return $this->items()->sum('amount') ?? 0;
    }

    /**
     * Получение краткой информации для логирования
     */
    public function getLogInfo(): array
    {
        return [
            'id' => $this->id,
            'guid_1c' => $this->guid_1c,
            'number' => $this->number,
            'date' => $this->date?->format('Y-m-d'),
            'type' => $this->payment_type,
            'amount' => $this->amount,
            'organization_id' => $this->organization_id,
            'counterparty_id' => $this->counterparty_id,
            'deletion_mark' => $this->deletion_mark,
        ];
    }
}
