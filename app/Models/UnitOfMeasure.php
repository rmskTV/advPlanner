<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель единицы измерения
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $guid_1c
 * @property string|null $code
 * @property string|null $name
 * @property string|null $full_name
 * @property string|null $symbol
 * @property bool $deletion_mark
 * @property \Carbon\Carbon|null $last_sync_at
 */
class UnitOfMeasure extends CatalogObject
{
    protected $table = 'units_of_measure';

    protected $fillable = [
        'guid_1c',
        'code',
        'name',
        'full_name',
        'symbol',
        'deletion_mark',
        'last_sync_at',
    ];

    protected $casts = [
        'deletion_mark' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    /**
     * Связь с номенклатурой
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'unit_of_measure_id');
    }

    /**
     * Поиск единицы измерения по GUID 1С
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
     * Обновление времени синхронизации
     */
    public function touchSync(): void
    {
        $this->update(['last_sync_at' => now()]);
    }
}
