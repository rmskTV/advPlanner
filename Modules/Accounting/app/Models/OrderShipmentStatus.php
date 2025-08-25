<?php

namespace Modules\Accounting\app\Models;

use App\Models\Registry;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель состояния отгрузки заказа
 *
 * Регистр сведений для отслеживания состояний отгрузки заказов клиентов.
 * Синхронизируется с 1С через объект "Справочник.СостояниеОтгрузкиЗаказа".
 *
 * @property int $id Первичный ключ
 * @property string $uuid UUID записи
 * @property string $order_guid_1c GUID заказа в 1С (уникальный ключ)
 * @property int|null $customer_order_id ID заказа клиента в системе
 * @property string $shipment_status Состояние отгрузки (Отгружен, НеОтгружен, ЧастичноОтгружен, и т.д.)
 * @property string|null $order_number Номер заказа (дублируется для случаев когда заказ не найден)
 * @property Carbon|null $order_date Дата заказа (дублируется для случаев когда заказ не найден)
 * @property string|null $organization_guid_1c GUID организации заказа в 1С
 * @property Carbon|null $last_sync_at Время последней синхронизации с 1С
 * @property Carbon $created_at Время создания записи
 * @property Carbon $updated_at Время последнего обновления записи
 * @property-read CustomerOrder|null $customerOrder Связанный заказ клиента
 *
 * @example
 * // Получение статуса отгрузки заказа
 * $status = OrderShipmentStatus::findByOrderGuid('some-guid-here');
 *
 * // Проверка статуса
 * if ($status && $status->shipment_status === 'Отгружен') {
 *     // Заказ отгружен
 * }
 *
 * // Получение связанного заказа
 * $order = $status->customerOrder;
 *
 * @see CustomerOrder Связанная модель заказа клиента
 */
class OrderShipmentStatus extends Registry
{
    protected $table = 'order_shipment_statuses';

    protected $fillable = [
        'order_guid_1c',
        'customer_order_id',
        'shipment_status',
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
