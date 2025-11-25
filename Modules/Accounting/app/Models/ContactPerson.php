<?php

namespace Modules\Accounting\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactPerson extends Model
{
    protected $table = 'contact_persons';

    protected $fillable = [
        'guid_1c',
        'counterparty_id',
        'counterparty_guid_1c',
        'last_name',
        'first_name',
        'middle_name',
        'full_name',
        'phone',
        'email',
        'position',
        'description',
        'deletion_mark',
        'is_active',
        'last_sync_at',
    ];

    protected $casts = [
        'deletion_mark' => 'boolean',
        'is_active' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    /**
     * Связь с контрагентом
     */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    /**
     * Получить полное ФИО
     */
    public function getFullNameAttribute(): ?string
    {
        if ($this->attributes['full_name'] ?? null) {
            return $this->attributes['full_name'];
        }

        $parts = array_filter([
            $this->last_name,
            $this->first_name,
            $this->middle_name,
        ]);

        return !empty($parts) ? implode(' ', $parts) : null;
    }

    /**
     * Поиск по GUID 1С
     */
    public static function findByGuid1C(string $guid): ?self
    {
        return static::where('guid_1c', $guid)->first();
    }

    /**
     * Получить короткое ФИО (Фамилия И.О.)
     */
    public function getShortName(): string
    {
        $short = $this->last_name ?? '';

        if ($this->first_name) {
            $short .= ' ' . mb_substr($this->first_name, 0, 1) . '.';
        }

        if ($this->middle_name) {
            $short .= mb_substr($this->middle_name, 0, 1) . '.';
        }

        return trim($short) ?: ($this->full_name ?? 'Unknown');
    }
}

