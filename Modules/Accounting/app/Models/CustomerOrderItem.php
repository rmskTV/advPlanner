<?php

namespace Modules\Accounting\app\Models;

use App\Models\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель строки заказа клиента
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_order_id
 * @property int $line_number
 * @property string|null $product_guid_1c
 * @property string|null $product_name
 * @property float|null $quantity
 * @property string|null $unit_guid_1c
 * @property string|null $unit_name
 * @property float|null $price
 * @property float|null $amount
 * @property float|null $vat_rate_value
 * @property float|null $vat_amount
 * @property string|null $content
 * @property array|null $characteristics
 */
class CustomerOrderItem extends Model
{
    protected $table = 'customer_order_items';

    protected $fillable = [
        'customer_order_id',
        'line_number',
        'product_guid_1c',
        'product_name',
        'quantity',
        'unit_guid_1c',
        'unit_name',
        'price',
        'amount',
        'vat_rate_value',
        'vat_amount',
        'content',
        'characteristics',
    ];

    protected $casts = [
        'line_number' => 'integer',
        'quantity' => 'decimal:3',
        'price' => 'decimal:2',
        'amount' => 'decimal:2',
        'vat_rate_value' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'characteristics' => 'array',
    ];

    /**
     * Связь с заказом
     */
    public function customerOrder(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class);
    }

    /**
     * Получение номенклатуры по GUID (если есть маппинг)
     */
    public function getProduct(): ?Product
    {
        if (!$this->product_guid_1c) {
            return null;
        }

        return Product::findByGuid1C($this->product_guid_1c);
    }

    /**
     * Получение единицы измерения по GUID (если есть маппинг)
     */
    public function getUnitOfMeasure(): ?UnitOfMeasure
    {
        if (!$this->unit_guid_1c) {
            return null;
        }

        return UnitOfMeasure::findByGuid1C($this->unit_guid_1c);
    }
}
