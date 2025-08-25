<?php

namespace Modules\Accounting\app\Models;

use App\Models\Document;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель заказа клиента
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $guid_1c
 * @property string|null $number
 * @property \Carbon\Carbon|null $date
 * @property int|null $organization_id
 * @property string|null $organization_guid_1c
 * @property string|null $counterparty_guid_1c
 * @property string|null $currency_guid_1c
 * @property float|null $amount
 * @property array|null $items
 * @property bool $amount_includes_vat
 * @property string|null $contract_guid_1c
 * @property string|null $settlement_currency_guid_1c
 * @property float|null $exchange_rate
 * @property float|null $exchange_multiplier
 * @property bool $calculations_in_conditional_units
 * @property string|null $delivery_address
 * @property string|null $organization_bank_account_guid_1c
 * @property string|null $responsible_guid_1c
 * @property bool $deletion_mark
 * @property \Carbon\Carbon|null $last_sync_at
 */
class CustomerOrder extends Document
{
    protected $table = 'customer_orders';

    protected $fillable = [
        'guid_1c',
        'number',
        'date',
        'organization_id',
        'organization_guid_1c',
        'counterparty_guid_1c',
        'currency_guid_1c',
        'amount',
        'amount_includes_vat',
        'contract_guid_1c',
        'settlement_currency_guid_1c',
        'exchange_rate',
        'exchange_multiplier',
        'calculations_in_conditional_units',
        'delivery_address',
        'organization_bank_account_guid_1c',
        'responsible_guid_1c',
        'deletion_mark',
        'last_sync_at',
    ];

    protected $casts = [
        'date' => 'datetime',
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'exchange_multiplier' => 'decimal:6',
        'amount_includes_vat' => 'boolean',
        'calculations_in_conditional_units' => 'boolean',
        'deletion_mark' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    /**
     * Связь с организацией
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Связь со строками заказа
     */
    public function items(): HasMany
    {
        return $this->hasMany(CustomerOrderItem::class)->orderBy('line_number');
    }

    /**
     * Поиск заказа по GUID 1С
     */
    public static function findByGuid1C(string $guid): ?self
    {
        return self::where('guid_1c', $guid)->first();
    }

    /**
     * Поиск заказа по номеру и дате
     */
    public static function findByNumberAndDate(string $number, $date): ?self
    {
        return self::where('number', $number)
            ->whereDate('date', $date)
            ->first();
    }

    /**
     * Получение общей суммы по строкам
     */
    public function getCalculatedAmount(): float
    {
        return $this->items()->sum('amount') ?? 0;
    }

    /**
     * Обновление времени синхронизации
     */
    public function touchSync(): void
    {
        $this->update(['last_sync_at' => now()]);
    }
}
