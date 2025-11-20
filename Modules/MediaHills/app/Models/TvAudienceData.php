<?php

namespace Modules\MediaHills\app\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TvAudienceData extends Model
{
    use HasFactory;

    protected $table = 'tv_audience_data';

    protected $fillable = [
        'channel_id',
        'datetime',
        'audience_value',
    ];

    protected $casts = [
        'datetime' => 'datetime',
        'audience_value' => 'decimal:3',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(TvChannel::class, 'channel_id');
    }

    /**
     * Scope для фильтрации по дате
     */
    public function scopeForDate($query, string $date)
    {
        return $query->whereDate('datetime', $date);
    }

    /**
     * Scope для фильтрации по периоду
     */
    public function scopeBetweenDates($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('datetime', [$startDate, $endDate]);
    }

    /**
     * Scope для фильтрации по времени суток
     */
    public function scopeForTimeRange($query, string $startTime, string $endTime)
    {
        return $query->whereTime('datetime', '>=', $startTime)
            ->whereTime('datetime', '<=', $endTime);
    }
}
