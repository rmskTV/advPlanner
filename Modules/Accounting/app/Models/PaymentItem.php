<?php

namespace Modules\Accounting\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель строки расшифровки платежа
 *
 * @property int $id
 * @property int $payment_id
 * @property int $line_number
 * @property int|null $order_id
 * @property string|null $order_guid_1c
 * @property float|null $amount
 * @property float|null $vat_amount
 * @property string|null $cash_flow_item_guid_1c
 * @property string|null $cash_flow_item_code
 * @property string|null $cash_flow_item_name
 * @property float|null $settlement_amount
 * @property int|null $contract_id
 * @property string|null $contract_guid_1c
 * @property int|null $settlement_currency_id
 * @property string|null $settlement_currency_guid_1c
 * @property float|null $exchange_rate
 * @property float|null $exchange_multiplier
 * @property string|null $advance_account
 * @property string|null $settlement_account
 * @property string|null $payment_type_extended
 * @property string|null $debt_repayment_method
 */
class PaymentItem extends Model
{
    protected $table = 'payment_items';

    protected $fillable = [
        'payment_id',
        'line_number',
        'order_id',
        'order_guid_1c',
        'amount',
        'vat_amount',
        'cash_flow_item_guid_1c',
        'cash_flow_item_code',
        'cash_flow_item_name',
        'settlement_amount',
        'contract_id',
        'contract_guid_1c',
        'settlement_currency_id',
        'settlement_currency_guid_1c',
        'exchange_rate',
        'exchange_multiplier',
        'advance_account',
        'settlement_account',
        'payment_type_extended',
        'debt_repayment_method',
    ];

    protected $casts = [
        'line_number' => 'integer',
        'amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'settlement_amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'exchange_multiplier' => 'decimal:6',
    ];

    /**
     * Связь с платежом
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Связь с заказом клиента
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class, 'order_id');
    }

    /**
     * Связь с договором
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Связь с валютой взаиморасчетов
     */
    public function settlementCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'settlement_currency_id');
    }
}
