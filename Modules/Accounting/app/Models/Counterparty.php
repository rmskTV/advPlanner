<?php

namespace Modules\Accounting\app\Models;

use App\Models\CatalogObject;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель контрагента
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $guid_1c
 * @property string $name
 * @property string|null $full_name
 * @property string|null $description
 * @property int|null $group_id
 * @property string|null $group_guid_1c
 * @property string $entity_type
 * @property string|null $inn
 * @property string|null $kpp
 * @property string|null $ogrn
 * @property string|null $okpo
 * @property string|null $country_guid_1c
 * @property string|null $country_code
 * @property string|null $country_name
 * @property string|null $registration_number
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $legal_address
 * @property string|null $legal_address_zip
 * @property string|null $actual_address
 * @property string|null $main_bank_account
 * @property string|null $main_bank_bik
 * @property string|null $main_bank_name
 * @property bool $is_our_company
 * @property bool $deletion_mark
 * @property bool $is_separate_division
 * @property Carbon|null $last_sync_at
 */
class Counterparty extends CatalogObject
{
    protected $table = 'counterparties';

    protected $fillable = [
        'guid_1c',
        'name',
        'full_name',
        'description',
        'group_id',
        'group_guid_1c',
        'entity_type',
        'inn',
        'kpp',
        'ogrn',
        'okpo',
        'country_guid_1c',
        'country_code',
        'country_name',
        'registration_number',
        'phone',
        'email',
        'legal_address',
        'legal_address_zip',
        'actual_address',
        'main_bank_account',
        'main_bank_bik',
        'main_bank_name',
        'is_our_company',
        'deletion_mark',
        'last_sync_at',
    ];

    protected $casts = [
        'entity_type' => 'string',
        'is_our_company' => 'boolean',
        'deletion_mark' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    // Константы для типов лиц
    public const ENTITY_TYPE_LEGAL = 'legal';

    public const ENTITY_TYPE_INDIVIDUAL = 'individual';

    /**
     * Связь с группой контрагентов
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CounterpartyGroup::class, 'group_id');
    }

    /**
     * Связь с договорами
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'counterparty_guid_1c', 'guid_1c');
    }

    /**
     * Поиск контрагента по GUID 1С
     */
    public static function findByGuid1C(string $guid): ?self
    {
        return self::where('guid_1c', $guid)->first();
    }

    /**
     * Поиск контрагента по ИНН
     */
    public static function findByInn(string $inn): ?self
    {
        return self::where('inn', $inn)->first();
    }

    /**
     * Проверка типа лица
     */
    public function isLegalEntity(): bool
    {
        return $this->entity_type === self::ENTITY_TYPE_LEGAL;
    }

    public function isIndividual(): bool
    {
        return $this->entity_type === self::ENTITY_TYPE_INDIVIDUAL;
    }

    /**
     * Обновление времени синхронизации
     */
    public function touchSync(): void
    {
        $this->update(['last_sync_at' => now()]);
    }

    public function sanitizeName(): string
    {
        $input = $this->full_name ?? $this->name;

        // Декодируем HTML entities
        $input = html_entity_decode($input, ENT_QUOTES, 'UTF-8');

        // Разрешенные символы: кириллица, цифры, пробелы, дефисы и: — & # , . ; ! ? ' ` + * № / : | _ % ° « » " ( )
        // Удаляем все остальные символы, кроме разрешенных
        $input = preg_replace('/[^\p{Cyrillic}\p{Latin}0-9\s\-—&#,.;!?\`\'\*\+№\/:\|_%°«»"()$$$$]/u', '', $input);

        // Ограничиваем длину
        $input = mb_substr($input, 0, 255);

        return trim($input);
    }
}
