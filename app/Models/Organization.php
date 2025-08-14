<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property string|null $guid_1c
 * @property string $name
 * @property string|null $full_name
 * @property string|null $prefix
 * @property string|null $inn
 * @property string|null $kpp
 * @property string|null $okpo
 * @property string|null $okato
 * @property string|null $oktmo
 * @property string|null $ogrn
 * @property string|null $okved
 * @property string|null $legal_address
 * @property string|null $legal_address_zip
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $website
 * @property string|null $main_bank_account
 * @property string|null $main_bank_bik
 * @property string|null $main_bank_name
 * @property string|null $director_name
 * @property string|null $director_position
 * @property string|null $accountant_name
 * @property bool $is_our_organization
 * @property bool $deletion_mark
 * @property \Carbon\Carbon|null $last_sync_at
 */
class Organization extends CatalogObject
{
    protected $table = 'organizations';

    protected $fillable = [
        'guid_1c',
        'name',
        'full_name',
        'prefix',
        'inn',
        'kpp',
        'okpo',
        'okato',
        'oktmo',
        'ogrn',
        'okved',
        'legal_address',
        'legal_address_zip',
        'phone',
        'email',
        'website',
        'main_bank_account',
        'main_bank_bik',
        'main_bank_name',
        'director_name',
        'director_position',
        'accountant_name',
        'is_our_organization',
        'deletion_mark',
        'last_sync_at',
    ];

    protected $casts = [
        'is_our_organization' => 'boolean',
        'deletion_mark' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    /**
     * Связь с банковскими счетами
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class, 'organization_id');
    }

    /**
     * Поиск организации по GUID 1С
     */
    public static function findByGuid1C(string $guid): ?self
    {
        return self::where('guid_1c', $guid)->first();
    }

    /**
     * Поиск организации по ИНН
     */
    public static function findByInn(string $inn): ?self
    {
        return self::where('inn', $inn)->first();
    }

    /**
     * Получение основной организации
     */
    public static function getMainOrganization(): ?self
    {
        return self::where('is_our_organization', true)->first();
    }

    /**
     * Обновление времени синхронизации
     */
    public function touchSync(): void
    {
        $this->update(['last_sync_at' => now()]);
    }
}
