<?php

namespace Modules\Accounting\app\Models;

use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель группы номенклатуры
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $guid_1c
 * @property string|null $name
 * @property string|null $code
 * @property string|null $description
 * @property int|null $parent_id
 * @property string|null $parent_guid_1c
 * @property bool $deletion_mark
 * @property \Carbon\Carbon|null $last_sync_at
 */
class ProductGroup extends CatalogObject
{
    protected $table = 'product_groups';

    protected $fillable = [
        'guid_1c',
        'name',
        'code',
        'description',
        'parent_id',
        'parent_guid_1c',
        'deletion_mark',
        'last_sync_at',
    ];

    protected $casts = [
        'deletion_mark' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    /**
     * Связь с родительской группой
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'parent_id');
    }

    /**
     * Связь с дочерними группами
     */
    public function children(): HasMany
    {
        return $this->hasMany(ProductGroup::class, 'parent_id');
    }

    /**
     * Связь с номенклатурой
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'group_id');
    }

    /**
     * Поиск группы по GUID 1С
     */
    public static function findByGuid1C(string $guid): ?self
    {
        return self::where('guid_1c', $guid)->first();
    }

    /**
     * Получение корневых групп
     */
    public static function getRootGroups()
    {
        return self::whereNull('parent_id')
            ->where('deletion_mark', false)
            ->orderBy('name')
            ->get();
    }

    /**
     * Обновление времени синхронизации
     */
    public function touchSync(): void
    {
        $this->update(['last_sync_at' => now()]);
    }
}
