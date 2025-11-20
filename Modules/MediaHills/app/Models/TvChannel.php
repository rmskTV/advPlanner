<?php

namespace Modules\MediaHills\app\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TvChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function audienceData(): HasMany
    {
        return $this->hasMany(TvAudienceData::class, 'channel_id');
    }

    /**
     * Найти или создать канал по имени
     */
    public static function findOrCreateByName(string $name): self
    {
        return static::firstOrCreate(['name' => $name]);
    }
}
