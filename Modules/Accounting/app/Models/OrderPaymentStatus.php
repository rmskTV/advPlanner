<?php

namespace Modules\Accounting\app\Models;

use App\Models\Registry;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель состояния оплаты заказа
 */
class OrderPaymentStatus extends Registry
{
    protected $table = 'order_payment_statuses';

    protected $fillable = [
        'order_guid_1c',
        'customer_order_id',
        'payment_status',
        'order_number',
        'order_date',
        'organization_guid_1c',
        'last_sync_at',
    ];

    protected $casts = [
        'order_date' => 'datetime',
        'last_sync_at' => 'datetime',
    ];

    public function customerOrder(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class);
    }

    public static function findByOrderGuid(string $orderGuid): ?self
    {
        return self::where('order_guid_1c', $orderGuid)->first();
    }
}
