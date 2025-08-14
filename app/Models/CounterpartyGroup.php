<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель группы контрагентов
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $guid_1c
 * @property string $name
 * @property string|null $description
 * @property int|null $parent_id
 * @property string|null $parent_guid_1c
 * @property bool $deletion_mark
 * @property \Carbon\Carbon|null $last_sync_at
 */
class CounterpartyGroup extends CatalogObject
{
    protected $table = 'counterparty_groups';

    protected $fillable = [
        'guid_1c',
        'name',
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
        return $this->belongsTo(CounterpartyGroup::class, 'parent_id');
    }

    /**
     * Связь с дочерними группами
     */
    public function children(): HasMany
    {
        return $this->hasMany(CounterpartyGroup::class, 'parent_id');
    }

    /**
     * Связь с контрагентами
     */
    public function counterparties(): HasMany
    {
        return $this->hasMany(Counterparty::class, 'group_id');
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
