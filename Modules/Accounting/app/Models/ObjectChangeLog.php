<?php

// Modules/Accounting/app/Models/ObjectChangeLog.php

namespace Modules\Accounting\app\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Bitrix24\app\Enums\SyncStatus;

class ObjectChangeLog extends Model
{
    protected $fillable = [
        'entity_type',
        'local_id',
        'guid_1c',
        'change_type',
        'status',
        'retry_count',
        'next_retry_at',
        'locked_at',
        'b24_id',
        'error',
        'source',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'locked_at' => 'datetime',
        'status' => SyncStatus::class,
    ];

    const MAX_RETRIES = 3;

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
            'status' => SyncStatus::PENDING,
            'received_at' => now(),
        ]);
    }

    public static function log1CChange($entityType, $oneСId, $localId)
    {
        return (new ObjectChangeLog)->create([
            'source' => self::SOURCE_1C,
            'entity_type' => $entityType,
            '1c_id' => $oneСId,
            'local_id' => $localId,
            'status' => SyncStatus::PENDING,
            'received_at' => now(),
        ]);
    }

    /**
     * Пометить как обработанный
     */
    public function markProcessed(): void
    {
        $this->update([
            'status' => SyncStatus::PROCESSED,
            'sent_at' => now(),
            'error' => null,
            'locked_at' => null,
        ]);
    }

    /**
     * Пометить для повторной попытки
     */
    public function markRetry(string $error): void
    {
        $nextRetry = $this->calculateNextRetry();

        $this->update([
            'status' => SyncStatus::RETRY,
            'error' => $error,
            'next_retry_at' => $nextRetry,
            'locked_at' => null,
        ]);
    }

    /**
     * Пометить как ошибка (финальная)
     */
    public function markError(string $error): void
    {
        $this->update([
            'status' => SyncStatus::ERROR,
            'error' => $error,
            'locked_at' => null,
        ]);
    }

    /**
     * Пометить как пропущенный
     */
    public function markSkipped(string $reason): void
    {
        $this->update([
            'status' => SyncStatus::SKIPPED,
            'error' => $reason,
            'locked_at' => null,
        ]);
    }

    /**
     * Захватить для обработки
     */
    public function lock(): bool
    {
        return $this->update([
            'status' => SyncStatus::PROCESSING,
            'locked_at' => now(),
        ]);
    }

    /**
     * Освободить блокировку
     */
    public function unlock(): void
    {
        $this->update(['locked_at' => null]);
    }

    /**
     * Расчет времени следующей попытки (экспоненциальный backoff)
     */
    protected function calculateNextRetry(): \DateTime
    {
        $delayMinutes = min(pow(2, $this->retry_count) * 5, 60); // 5m, 10m, 20m, 40m, max 60m

        return now()->addMinutes($delayMinutes);
    }

    /**
     * Скоуп для получения готовых к обработке записей
     */
    public function scopeReadyForProcessing($query, ?array $supportedEntityTypes = null, ?string $source = null)
    {
        $query->whereIn('status', [SyncStatus::PENDING, SyncStatus::RETRY])
            ->where(function ($q) {
                $q->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->whereNull('locked_at')
            ->where('retry_count', '<', self::MAX_RETRIES);

        // Фильтр по источнику (если указан)
        if ($source !== null) {
            $query->where('source', $source);
        }

        // Фильтр по типам сущностей (если указан)
        if ($supportedEntityTypes !== null && ! empty($supportedEntityTypes)) {
            $query->whereIn('entity_type', $supportedEntityTypes);
        }

        return $query;
    }

    /**
     * Скоуп для зависших записей (locked > 10 минут)
     */
    public function scopeStale($query, int $minutes = 10)
    {
        return $query
            ->where('status', SyncStatus::PROCESSING)
            ->where('locked_at', '<', now()->subMinutes($minutes));
    }
}
