<?php

namespace Modules\Accounting\app\Models;

use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель валюты
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $guid_1c
 * @property string|null $code
 * @property string|null $name
 * @property string|null $full_name
 * @property string|null $spelling_parameters
 * @property string|null $symbol
 * @property int $decimal_places
 * @property bool $is_main_currency
 * @property bool $deletion_mark
 * @property \Carbon\Carbon|null $last_sync_at
 */
class Currency extends CatalogObject
{
    protected $table = 'currencies';

    protected $fillable = [
        'guid_1c',
        'code',
        'name',
        'full_name',
        'spelling_parameters',
        'symbol',
        'decimal_places',
        'is_main_currency',
        'deletion_mark',
        'last_sync_at',
    ];

    protected $casts = [
        'decimal_places' => 'integer',
        'is_main_currency' => 'boolean',
        'deletion_mark' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    /**
     * Связь с договорами
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'currency_guid_1c', 'guid_1c');
    }

    /**
     * Поиск валюты по GUID 1С
     */
    public static function findByGuid1C(string $guid): ?self
    {
        return self::where('guid_1c', $guid)->first();
    }

    /**
     * Поиск валюты по коду
     */
    public static function findByCode(string $code): ?self
    {
        return self::where('code', $code)->first();
    }

    /**
     * Получение основной валюты
     */
    public static function getMainCurrency(): ?self
    {
        return self::where('is_main_currency', true)->first();
    }

    /**
     * Обновление времени синхронизации
     */
    public function touchSync(): void
    {
        $this->update(['last_sync_at' => now()]);
    }
}
