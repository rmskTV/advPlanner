<?php

namespace Modules\EnterpriseData\app\Models;

use App\Models\Registry;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $connector_id
 * @property string $object_type
 * @property string|null $version
 * @property int $occurrence_count
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property array|null $sample_data
 * @property string $mapping_status
 * @property string|null $notes
 */
class ExchangeUnmappedObject extends Registry
{
    protected $table = 'exchange_unmapped_objects';

    protected $fillable = [
        'connector_id',
        'object_type',
        'version',
        'occurrence_count',
        'first_seen_at',
        'last_seen_at',
        'sample_data',
        'mapping_status',
        'notes',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'sample_data' => 'array',
        'occurrence_count' => 'integer',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_IGNORED = 'ignored';

    public function connector(): BelongsTo
    {
        return $this->belongsTo(ExchangeFtpConnector::class, 'connector_id');
    }

    public function incrementOccurrence(): void
    {
        $this->increment('occurrence_count');
        $this->update(['last_seen_at' => now()]);
    }

    public static function recordUnmappedObject(
        int $connectorId,
        string $objectType,
        array $sampleObject,
        ?string $version = null
    ): void {
        $existing = self::where('connector_id', $connectorId)
            ->where('object_type', $objectType)
            ->first();

        if ($existing) {
            $existing->incrementOccurrence();

            // Обновляем пример данных если текущий более полный
            if (empty($existing->sample_data) ||
                count($sampleObject['properties'] ?? []) > count($existing->sample_data['properties'] ?? [])) {
                $existing->update(['sample_data' => $sampleObject]);
            }
        } else {
            self::create([
                'connector_id' => $connectorId,
                'object_type' => $objectType,
                'version' => $version,
                'occurrence_count' => 1,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'sample_data' => $sampleObject,
                'mapping_status' => self::STATUS_PENDING,
            ]);
        }
    }
}
