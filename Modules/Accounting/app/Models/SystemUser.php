<?php

namespace Modules\Accounting\app\Models;

use App\Models\CatalogObject;
use App\Models\Individual;

/**
 * Модель системного пользователя 1С
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $guid_1c
 * @property string|null $name
 * @property string|null $login
 * @property string|null $description
 * @property string|null $individual_guid_1c
 * @property bool $is_active
 * @property bool $deletion_mark
 * @property \Carbon\Carbon|null $last_sync_at
 */
class SystemUser extends CatalogObject
{
    protected $table = 'system_users';

    protected $fillable = [
        'guid_1c',
        'name',
        'login',
        'description',
        'individual_guid_1c',
        'is_active',
        'deletion_mark',
        'last_sync_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'deletion_mark' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    /**
     * Связь с физическим лицом (через GUID)
     */
    public function individual(): ?Individual
    {
        if (! $this->individual_guid_1c) {
            return null;
        }

        return Individual::findByGuid1C($this->individual_guid_1c);
    }

    /**
     * Поиск пользователя по GUID 1С
     */
    public static function findByGuid1C(string $guid): ?self
    {
        return self::where('guid_1c', $guid)->first();
    }

    /**
     * Обновление времени синхронизации
     */
    public function touchSync(): void
    {
        $this->update(['last_sync_at' => now()]);
    }
}
