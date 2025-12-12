<?php

namespace Modules\Accounting\app\Models;

use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\VkAds\app\Models\VkAdsAccount;

/**
 * Организации
 *
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
 * @property string|null $okopf
 * @property string|null $okfs
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

    /**
     * Аккаунт Vk Ads уровня Организации (Аккаунт рекламного агентства)
     */
    public function vkAdsAccount(): HasOne
    {
        return $this->hasOne(VkAdsAccount::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    /**
     * Получить активные банковские счета
     */
    public function activeBankAccounts(): HasMany
    {
        return $this->bankAccounts()
            ->where('is_active', true)
            ->where('deletion_mark', false);
    }
}
