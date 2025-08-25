<?php

namespace Modules\Accounting\app\Models;

use App\Models\Registry;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель состояния оплаты заказа
 *
 * Регистр сведений для отслеживания состояний оплаты заказов клиентов.
 * Синхронизируется с 1С через объект "Справочник.СостояниеОплатыЗаказа".
 *
 * @property int $id Первичный ключ
 * @property string $uuid UUID записи
 * @property string $order_guid_1c GUID заказа в 1С (уникальный ключ)
 * @property int|null $customer_order_id ID заказа клиента в системе
 * @property string $payment_status Состояние оплаты (Оплачен, НеОплачен, ЧастичноОплачен, и т.д.)
 * @property string|null $order_number Номер заказа (дублируется для случаев когда заказ не найден)
 * @property Carbon|null $order_date Дата заказа (дублируется для случаев когда заказ не найден)
 * @property string|null $organization_guid_1c GUID организации заказа в 1С
 * @property Carbon|null $last_sync_at Время последней синхронизации с 1С
 * @property Carbon $created_at Время создания записи
 * @property Carbon $updated_at Время последнего обновления записи
 * @property-read CustomerOrder|null $customerOrder Связанный заказ клиента
 *
 * @example
 * // Получение статуса оплаты заказа
 * $status = OrderPaymentStatus::findByOrderGuid('some-guid-here');
 *
 * // Проверка статуса
 * if ($status && $status->payment_status === 'Оплачен') {
 *     // Заказ оплачен
 * }
 *
 * @see CustomerOrder Связанная модель заказа клиента
 * @see OrderShipmentStatus Модель состояния отгрузки заказа
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
