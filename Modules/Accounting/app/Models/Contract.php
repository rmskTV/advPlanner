<?php

namespace Modules\Accounting\app\Models;

use App\Models\CatalogObject;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\VkAds\app\Models\VkAdsAccount;

/**
 * Модель договора
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $guid_1c
 * @property string|null $number
 * @property Carbon|null $date
 * @property string $name
 * @property string|null $description
 * @property int|null $organization_id
 * @property string|null $counterparty_guid_1c
 * @property string|null $currency_guid_1c
 * @property string|null $contract_type
 * @property string|null $contract_category
 * @property float|null $amount
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property int|null $payment_days
 * @property string|null $payment_terms
 * @property bool $is_agent_contract
 * @property string|null $agent_contract_type
 * @property bool $calculations_in_conditional_units
 * @property bool $is_active
 * @property bool $deletion_mark
 * @property Carbon|null $last_sync_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property Organization|null $organization
 */
class Contract extends CatalogObject
{
    protected $table = 'contracts';

    protected $fillable = [
        'guid_1c',
        'number',
        'date',
        'name',
        'description',
        'organization_id',
        'counterparty_guid_1c',
        'currency_guid_1c',
        'contract_type',
        'contract_category',
        'amount',
        'valid_from',
        'valid_to',
        'payment_days',
        'payment_terms',
        'is_agent_contract',
        'agent_contract_type',
        'calculations_in_conditional_units',
        'is_active',
        'deletion_mark',
        'last_sync_at',
    ];

    protected $casts = [
        'date' => 'date',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'amount' => 'decimal:2',
        'payment_days' => 'integer',
        'is_agent_contract' => 'boolean',
        'calculations_in_conditional_units' => 'boolean',
        'is_active' => 'boolean',
        'deletion_mark' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    protected $appends = [
        'full_name',
        'status_label',
        'is_currently_active',
    ];

    // Константы для типов договоров
    public const TYPE_WITH_CUSTOMER = 'СПокупателем';

    public const TYPE_WITH_SUPPLIER = 'СПоставщиком';

    public const TYPE_COMMISSION_WITH_PRINCIPAL = 'КомиссииСКомитентом';

    public const TYPE_COMMISSION_WITH_AGENT = 'КомиссииСКомиссионером';

    public const TYPE_AGENT = 'Агентский';

    public const TYPE_SUBCONTRACT = 'Субподряда';

    public static function getContractTypes(): array
    {
        return [
            self::TYPE_WITH_CUSTOMER => 'С покупателем',
            self::TYPE_WITH_SUPPLIER => 'С поставщиком',
            self::TYPE_COMMISSION_WITH_PRINCIPAL => 'Комиссии с комитентом',
            self::TYPE_COMMISSION_WITH_AGENT => 'Комиссии с комиссионером',
            self::TYPE_AGENT => 'Агентский',
            self::TYPE_SUBCONTRACT => 'Субподряда',
        ];
    }

    /**
     * Связь с организацией
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class, 'counterparty_guid_1c', 'guid_1c');
    }

    /**
     * Связь с заказами клиентов (если будет создана модель)
     */
    public function customerOrders(): HasMany
    {
        return $this->hasMany(CustomerOrder::class, 'contract_guid_1c', 'guid_1c');
    }

    /**
     * Связь с реализациями (если будет создана модель)
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'contract_guid_1c', 'guid_1c');
    }

    /**
     * Поиск договора по GUID 1С
     */
    public static function findByGuid1C(string $guid): ?self
    {
        return self::where('guid_1c', $guid)->first();
    }

    /**
     * Поиск договора по номеру и дате
     */
    public static function findByNumberAndDate(string $number, $date): ?self
    {
        return self::where('number', $number)
            ->whereDate('date', $date)
            ->first();
    }

    /**
     * Поиск договоров контрагента
     */
    public static function findByCounterparty(string $counterpartyGuid): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('counterparty_guid_1c', $counterpartyGuid)
            ->where('is_active', true)
            ->where('deletion_mark', false)
            ->get();
    }

    /**
     * Scope для активных договоров
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('deletion_mark', false);
    }

    /**
     * Scope для договоров определенного типа
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('contract_type', $type);
    }

    /**
     * Scope для действующих договоров на дату
     */
    public function scopeValidOn(Builder $query, $date = null): Builder
    {
        $date = $date ? Carbon::parse($date)->format('Y-m-d') : now()->format('Y-m-d');

        return $query->where(function ($q) use ($date) {
            $q->whereNull('valid_from')
                ->orWhere('valid_from', '<=', $date);
        })->where(function ($q) use ($date) {
            $q->whereNull('valid_to')
                ->orWhere('valid_to', '>=', $date);
        });
    }

    /**
     * Scope для договоров организации
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Accessor для полного наименования
     */
    public function getFullNameAttribute(): string
    {
        return "Договор {$this->contract_type} № {$this->number} от {$this->date->format('d.m.Y')} г.";
    }

    /**
     * Accessor для статуса договора
     */
    public function getStatusLabelAttribute(): string
    {
        if ($this->deletion_mark) {
            return 'Помечен к удалению';
        }

        if (! $this->is_active) {
            return 'Неактивный';
        }

        if ($this->isCurrentlyActive()) {
            return 'Действующий';
        }

        return 'Недействующий';
    }

    /**
     * Accessor для проверки текущей активности
     */
    public function getIsCurrentlyActiveAttribute(): bool
    {
        return $this->isCurrentlyActive();
    }

    /**
     * Обновление времени синхронизации
     */
    public function touchSync(): void
    {
        $this->update(['last_sync_at' => now()]);
    }

    /**
     * Проверка активности договора на текущую дату
     */
    public function isCurrentlyActive(): bool
    {
        if (! $this->is_active || $this->deletion_mark) {
            return false;
        }

        $now = now()->toDateString();

        if ($this->valid_from && $this->valid_from->format('Y-m-d') > $now) {
            return false;
        }

        if ($this->valid_to && $this->valid_to->format('Y-m-d') < $now) {
            return false;
        }

        return true;
    }

    /**
     * Проверка истечения срока действия
     */
    public function isExpired(): bool
    {
        return $this->valid_to && $this->valid_to->isPast();
    }

    /**
     * Проверка будущего вступления в силу
     */
    public function isFuture(): bool
    {
        return $this->valid_from && $this->valid_from->isFuture();
    }

    /**
     * Получение типа договора на русском языке
     */
    public function getContractTypeLabel(): string
    {
        return self::getContractTypes()[$this->contract_type] ?? $this->contract_type;
    }

    /**
     * Проверка типа договора
     */
    public function isCustomerContract(): bool
    {
        return $this->contract_type === self::TYPE_WITH_CUSTOMER;
    }

    public function isSupplierContract(): bool
    {
        return $this->contract_type === self::TYPE_WITH_SUPPLIER;
    }

    public function isCommissionContract(): bool
    {
        return in_array($this->contract_type, [
            self::TYPE_COMMISSION_WITH_PRINCIPAL,
            self::TYPE_COMMISSION_WITH_AGENT,
        ]);
    }

    public function isAgentContract(): bool
    {
        return $this->contract_type === self::TYPE_AGENT || $this->is_agent_contract;
    }

    /**
     * Получение краткой информации для логирования
     */
    public function getLogInfo(): array
    {
        return [
            'id' => $this->id,
            'guid_1c' => $this->guid_1c,
            'number' => $this->number,
            'date' => $this->date->format('Y-m-d'),
            'name' => $this->name,
            'type' => $this->contract_type,
            'organization_id' => $this->organization_id,
            'counterparty_guid' => $this->counterparty_guid_1c,
            'is_active' => $this->is_active,
            'deletion_mark' => $this->deletion_mark,
        ];
    }

    /**
     * Валидация данных договора
     */
    public function validateContractData(): array
    {
        $errors = [];
        $warnings = [];

        // Проверка обязательных полей
        if (empty($this->number)) {
            $errors[] = 'Номер договора обязателен';
        }

        if (! $this->date) {
            $errors[] = 'Дата договора обязательна';
        }

        if (empty($this->name)) {
            $errors[] = 'Наименование договора обязательно';
        }

        // Проверка логики дат
        if ($this->valid_from && $this->valid_to && $this->valid_from->gt($this->valid_to)) {
            $errors[] = 'Дата начала действия не может быть больше даты окончания';
        }

        // Проверка суммы
        if ($this->amount && $this->amount < 0) {
            $warnings[] = 'Сумма договора отрицательная';
        }

        // Проверка связей
        if (! $this->organization_id) {
            $warnings[] = 'Не указана организация';
        }

        if (! $this->counterparty_guid_1c) {
            $warnings[] = 'Не указан контрагент';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Аккаунт Vk Ads уровня контрагента (Кабинет клиента), привязывается к договору с контрагентом
     */
    public function vkAdsAccount(): HasOne
    {
        return $this->hasOne(VkAdsAccount::class);
    }
}
