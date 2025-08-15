<?php

namespace Modules\Accounting\app\Models;

use App\Models\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель строки реализации
 *
 * @property int $id
 * @property string $uuid
 * @property int $sale_id
 * @property int $line_number
 * @property string|null $line_identifier
 * @property string|null $product_guid_1c
 * @property string|null $product_name
 * @property float|null $quantity
 * @property string|null $unit_guid_1c
 * @property string|null $unit_name
 * @property float|null $price
 * @property float|null $amount
 * @property float|null $vat_amount
 * @property string|null $content
 * @property string|null $service_type
 * @property string|null $income_account
 * @property string|null $expense_account
 * @property string|null $vat_account
 * @property array|null $characteristics
 */
class SaleItem extends Model
{
    protected $table = 'sale_items';

    protected $fillable = [
        'sale_id',
        'line_number',
        'line_identifier',
        'product_guid_1c',
        'product_name',
        'quantity',
        'unit_guid_1c',
        'unit_name',
        'price',
        'amount',
        'vat_amount',
        'content',
        'service_type',
        'income_account',
        'expense_account',
        'vat_account',
        'characteristics',
    ];

    protected $casts = [
        'line_number' => 'integer',
        'quantity' => 'decimal:3',
        'price' => 'decimal:2',
        'amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'characteristics' => 'array',
    ];

    /**
     * Связь с реализацией
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * Получение номенклатуры по GUID
     */
    public function getProduct(): ?Product
    {
        if (!$this->product_guid_1c) {
            return null;
        }

        return Product::findByGuid1C($this->product_guid_1c);
    }

    /**
     * Получение единицы измерения по GUID
     */
    public function getUnitOfMeasure(): ?UnitOfMeasure
    {
        if (!$this->unit_guid_1c) {
            return null;
        }

        return UnitOfMeasure::findByGuid1C($this->unit_guid_1c);
    }
}
