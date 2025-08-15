<?php

namespace Modules\Accounting\app\Models;

use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель номенклатуры
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $guid_1c
 * @property string|null $name
 * @property string|null $full_name
 * @property string|null $code
 * @property string|null $description
 * @property int|null $group_id
 * @property string|null $group_guid_1c
 * @property string $product_type
 * @property int|null $unit_of_measure_id
 * @property string|null $unit_guid_1c
 * @property string|null $vat_rate
 * @property string|null $tru_code
 * @property string|null $analytics_group_guid_1c
 * @property string|null $analytics_group_code
 * @property string|null $analytics_group_name
 * @property string|null $product_kind_guid_1c
 * @property string|null $product_kind_name
 * @property bool $is_alcoholic
 * @property string|null $alcohol_type
 * @property bool $is_imported_alcohol
 * @property float|null $alcohol_volume
 * @property string|null $alcohol_producer
 * @property bool $is_traceable
 * @property bool $deletion_mark
 * @property \Carbon\Carbon|null $last_sync_at
 */
class Product extends CatalogObject
{
    protected $table = 'products';

    protected $fillable = [
        'guid_1c',
        'name',
        'full_name',
        'code',
        'description',
        'group_id',
        'group_guid_1c',
        'product_type',
        'unit_of_measure_id',
        'unit_guid_1c',
        'vat_rate',
        'tru_code',
        'analytics_group_guid_1c',
        'analytics_group_code',
        'analytics_group_name',
        'product_kind_guid_1c',
        'product_kind_name',
        'is_alcoholic',
        'alcohol_type',
        'is_imported_alcohol',
        'alcohol_volume',
        'alcohol_producer',
        'is_traceable',
        'deletion_mark',
        'last_sync_at',
    ];

    protected $casts = [
        'alcohol_volume' => 'decimal:4',
        'is_alcoholic' => 'boolean',
        'is_imported_alcohol' => 'boolean',
        'is_traceable' => 'boolean',
        'deletion_mark' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    // Константы для типов номенклатуры
    public const TYPE_PRODUCT = 'product';

    public const TYPE_SERVICE = 'service';

    public const TYPE_SET = 'set';

    /**
     * Связь с группой номенклатуры
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'group_id');
    }

    /**
     * Связь с единицей измерения
     */
    public function unitOfMeasure(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_of_measure_id');
    }

    /**
     * Поиск номенклатуры по GUID 1С
     */
    public static function findByGuid1C(string $guid): ?self
    {
        return self::where('guid_1c', $guid)->first();
    }

    /**
     * Поиск по коду
     */
    public static function findByCode(string $code): ?self
    {
        return self::where('code', $code)->first();
    }

    /**
     * Проверка типов
     */
    public function isProduct(): bool
    {
        return $this->product_type === self::TYPE_PRODUCT;
    }

    public function isService(): bool
    {
        return $this->product_type === self::TYPE_SERVICE;
    }

    public function isSet(): bool
    {
        return $this->product_type === self::TYPE_SET;
    }

    /**
     * Обновление времени синхронизации
     */
    public function touchSync(): void
    {
        $this->update(['last_sync_at' => now()]);
    }
}
