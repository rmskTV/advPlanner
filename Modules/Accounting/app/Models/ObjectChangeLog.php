<?php

namespace Modules\Accounting\app\Models;

use App\Models\Model;

class ObjectChangeLog extends Model
{
    protected $fillable = [
        'source',
        'entity_type',
        'b24_id',
        '1c_id',
        'local_id',
        'status',
        'received_at',
        'sent_at',
        'error'
    ];

    protected $dates = [
        'received_at',
        'sent_at',
        'created_at',
        'updated_at'
    ];

    // Константы для статусов
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSED = 'processed';
    const STATUS_ERROR = 'error';

    // Константы для источников
    const SOURCE_B24 = 'B24';
    const SOURCE_1C = '1C';

    public static function logB24Change($entityType, $b24Id, $localId)
    {
        return (new ObjectChangeLog)->create([
            'source' => self::SOURCE_B24,
            'entity_type' => $entityType,
            'b24_id' => $b24Id,
            'local_id' => $localId,
            'status' => self::STATUS_PENDING,
            'received_at' => now()
        ]);
    }

    public static function log1CChange($entityType, $oneСId, $localId)
    {
        return (new ObjectChangeLog)->create([
            'source' => self::SOURCE_1C,
            'entity_type' => $entityType,
            '1c_id' => $oneСId,
            'local_id' => $localId,
            'status' => self::STATUS_PENDING,
            'received_at' => now()
        ]);
    }

    public function markProcessed(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSED,
            'sent_at' => now()
        ]);
    }

    public function markError($errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_ERROR,
            'error' => $errorMessage
        ]);
    }
}
