<?php

namespace Modules\EnterpriseData\app\Models;

use App\Models\Registry;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Модель журнала операций обмена данными
 *
 * @property int $id
 * @property string $uuid
 * @property int $connector_id
 * @property string $direction
 * @property int|null $message_no
 * @property string|null $file_name
 * @property int|null $objects_count
 * @property string $status
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int|null $duration_seconds
 * @property array|null $errors
 * @property array|null $warnings
 * @property array|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property ExchangeFtpConnector $connector
 * @property Collection|ObjectChangeLog[] $objectChanges
 */
class ExchangeLog extends Registry
{
    protected $table = 'exchange_logs';

    protected $fillable = [
        'connector_id',
        'direction',
        'message_no',
        'file_name',
        'objects_count',
        'status',
        'started_at',
        'completed_at',
        'duration_seconds',
        'errors',
        'warnings',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'errors' => 'array',
        'warnings' => 'array',
        'metadata' => 'array',
        'objects_count' => 'integer',
        'message_no' => 'integer',
        'duration_seconds' => 'integer',
    ];

    protected $hidden = [
        // Скрываем потенциально чувствительные данные при сериализации
    ];

    protected $appends = [
        'status_label',
        'direction_label',
        'duration_formatted',
        'success_rate',
    ];

    // Константы для статусов
    public const STATUS_STARTED = 'started';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    // Константы для направлений
    public const DIRECTION_INCOMING = 'incoming';

    public const DIRECTION_OUTGOING = 'outgoing';

    public const DIRECTION_BOTH = 'both';

    public static function getStatuses(): array
    {
        return [
            self::STATUS_STARTED => 'Запущен',
            self::STATUS_PROCESSING => 'Обрабатывается',
            self::STATUS_COMPLETED => 'Завершен',
            self::STATUS_FAILED => 'Ошибка',
            self::STATUS_CANCELLED => 'Отменен',
        ];
    }

    public static function getDirections(): array
    {
        return [
            self::DIRECTION_INCOMING => 'Входящий',
            self::DIRECTION_OUTGOING => 'Исходящий',
            self::DIRECTION_BOTH => 'Двусторонний',
        ];
    }

    /**
     * Связь с коннектором обмена
     */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(ExchangeFtpConnector::class, 'connector_id');
    }

    /**
     * Связь с изменениями объектов
     */
    public function objectChanges(): HasMany
    {
        return $this->hasMany(ObjectChangeLog::class, 'exchange_log_id');
    }

    /**
     * Accessor для получения читаемого статуса
     */
    protected function statusLabel(): Attribute
    {
        return Attribute::make(
            get: fn () => self::getStatuses()[$this->status] ?? $this->status
        );
    }

    /**
     * Accessor для получения читаемого направления
     */
    protected function directionLabel(): Attribute
    {
        return Attribute::make(
            get: fn () => self::getDirections()[$this->direction] ?? $this->direction
        );
    }

    /**
     * Accessor для форматированной длительности
     */
    protected function durationFormatted(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->duration_seconds) {
                    return null;
                }

                $minutes = intval($this->duration_seconds / 60);
                $seconds = $this->duration_seconds % 60;

                if ($minutes > 0) {
                    return "{$minutes}м {$seconds}с";
                }

                return "{$seconds}с";
            }
        );
    }

    /**
     * Accessor для расчета процента успешности
     */
    protected function successRate(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->objects_count || $this->objects_count === 0) {
                    return null;
                }

                $errorCount = is_array($this->errors) ? count($this->errors) : 0;
                $successCount = max(0, $this->objects_count - $errorCount);

                return round(($successCount / $this->objects_count) * 100, 2);
            }
        );
    }

    /**
     * Mutator для безопасного сохранения ошибок
     */
    protected function errors(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? json_decode($value, true) : [],
            set: fn ($value) => $this->sanitizeErrorsForStorage($value)
        );
    }

    /**
     * Mutator для безопасного сохранения предупреждений
     */
    protected function warnings(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? json_decode($value, true) : [],
            set: fn ($value) => $this->sanitizeWarningsForStorage($value)
        );
    }

    /**
     * Mutator для безопасного сохранения метаданных
     */
    protected function metadata(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? json_decode($value, true) : [],
            set: fn ($value) => $this->sanitizeMetadataForStorage($value)
        );
    }

    /**
     * Scope для фильтрации по статусу
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope для успешных операций
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope для неудачных операций
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope для фильтрации по направлению
     */
    public function scopeDirection(Builder $query, string $direction): Builder
    {
        return $query->where('direction', $direction);
    }

    /**
     * Scope для фильтрации по коннектору
     */
    public function scopeForConnector(Builder $query, int $connectorId): Builder
    {
        return $query->where('connector_id', $connectorId);
    }

    /**
     * Scope для фильтрации по периоду
     */
    public function scopeBetweenDates(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    /**
     * Scope для недавних записей
     */
    public function scopeRecent(Builder $query, int $hours = 24): Builder
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    /**
     * Scope для медленных операций
     */
    public function scopeSlow(Builder $query, int $thresholdSeconds = 60): Builder
    {
        return $query->where('duration_seconds', '>', $thresholdSeconds);
    }

    /**
     * Scope для операций с ошибками
     */
    public function scopeWithErrors(Builder $query): Builder
    {
        return $query->whereNotNull('errors')
            ->where('errors', '!=', '[]')
            ->where('errors', '!=', 'null');
    }

    /**
     * Проверка успешности операции
     */
    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Проверка наличия ошибок
     */
    public function hasErrors(): bool
    {
        return ! empty($this->errors);
    }

    /**
     * Проверка наличия предупреждений
     */
    public function hasWarnings(): bool
    {
        return ! empty($this->warnings);
    }

    /**
     * Проверка медленной операции
     */
    public function isSlow(int $thresholdSeconds = 60): bool
    {
        return $this->duration_seconds && $this->duration_seconds > $thresholdSeconds;
    }

    /**
     * Получение количества ошибок
     */
    public function getErrorCount(): int
    {
        return is_array($this->errors) ? count($this->errors) : 0;
    }

    /**
     * Получение количества предупреждений
     */
    public function getWarningCount(): int
    {
        return is_array($this->warnings) ? count($this->warnings) : 0;
    }

    /**
     * Добавление ошибки к записи
     */
    public function addError(string $error): void
    {
        $errors = $this->errors ?? [];
        $errors[] = $this->sanitizeMessage($error);
        $this->errors = $errors;
    }

    /**
     * Добавление предупреждения к записи
     */
    public function addWarning(string $warning): void
    {
        $warnings = $this->warnings ?? [];
        $warnings[] = $this->sanitizeMessage($warning);
        $this->warnings = $warnings;
    }

    /**
     * Добавление метаданных
     */
    public function addMetadata(string $key, mixed $value): void
    {
        $metadata = $this->metadata ?? [];
        $metadata[$key] = $this->sanitizeValue($value);
        $this->metadata = $metadata;
    }

    /**
     * Отметка начала операции
     */
    public function markAsStarted(): void
    {
        $this->update([
            'status' => self::STATUS_STARTED,
            'started_at' => now(),
        ]);
    }

    /**
     * Отметка завершения операции
     */
    public function markAsCompleted(): void
    {
        $completedAt = now();
        $duration = $this->started_at ? $completedAt->diffInSeconds($this->started_at) : null;

        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => $completedAt,
            'duration_seconds' => $duration,
        ]);
    }

    /**
     * Отметка неудачного завершения операции
     */
    public function markAsFailed(?string $error = null): void
    {
        $completedAt = now();
        $duration = $this->started_at ? $completedAt->diffInSeconds($this->started_at) : null;

        $updateData = [
            'status' => self::STATUS_FAILED,
            'completed_at' => $completedAt,
            'duration_seconds' => $duration,
        ];

        if ($error) {
            $this->addError($error);
            $updateData['errors'] = $this->errors;
        }

        $this->update($updateData);
    }

    /**
     * Получение статистики по коннектору
     */
    public static function getConnectorStatistics(int $connectorId, int $days = 30): array
    {
        $from = now()->subDays($days);

        $logs = self::forConnector($connectorId)
            ->where('created_at', '>=', $from)
            ->get();

        $successful = $logs->where('status', self::STATUS_COMPLETED);
        $failed = $logs->where('status', self::STATUS_FAILED);

        return [
            'total' => $logs->count(),
            'successful' => $successful->count(),
            'failed' => $failed->count(),
            'success_rate' => $logs->count() > 0 ? round(($successful->count() / $logs->count()) * 100, 2) : 0,
            'total_objects' => $successful->sum('objects_count'),
            'avg_duration' => $successful->avg('duration_seconds'),
            'slow_operations' => $logs->where('duration_seconds', '>', 60)->count(),
            'period_days' => $days,
        ];
    }

    /**
     * Очистка старых записей
     */
    public static function cleanup(int $days = 30): int
    {
        return self::where('created_at', '<', now()->subDays($days))->delete();
    }

    /**
     * Безопасная санитизация ошибок для хранения
     */
    private function sanitizeErrorsForStorage(mixed $errors): ?string
    {
        if (empty($errors)) {
            return null;
        }

        if (is_string($errors)) {
            $errors = [$errors];
        }

        if (! is_array($errors)) {
            return null;
        }

        $sanitized = array_map([$this, 'sanitizeMessage'], $errors);

        return json_encode(array_slice($sanitized, 0, 50)); // Ограничиваем количество ошибок
    }

    /**
     * Безопасная санитизация предупреждений для хранения
     */
    private function sanitizeWarningsForStorage(mixed $warnings): ?string
    {
        if (empty($warnings)) {
            return null;
        }

        if (is_string($warnings)) {
            $warnings = [$warnings];
        }

        if (! is_array($warnings)) {
            return null;
        }

        $sanitized = array_map([$this, 'sanitizeMessage'], $warnings);

        return json_encode(array_slice($sanitized, 0, 50)); // Ограничиваем количество предупреждений
    }

    /**
     * Безопасная санитизация метаданных для хранения
     */
    private function sanitizeMetadataForStorage(mixed $metadata): ?string
    {
        if (empty($metadata)) {
            return null;
        }

        if (! is_array($metadata)) {
            return null;
        }

        $sanitized = $this->sanitizeArray($metadata);

        return json_encode($sanitized);
    }

    /**
     * Санитизация сообщения
     */
    private function sanitizeMessage(string $message): string
    {
        // Ограничиваем длину сообщения
        if (strlen($message) > 500) {
            $message = substr($message, 0, 500).'... [truncated]';
        }

        // Удаляем потенциально чувствительную информацию
        $patterns = [
            '/password[=:]\s*\S+/i' => 'password=[REDACTED]',
            '/token[=:]\s*\S+/i' => 'token=[REDACTED]',
            '/key[=:]\s*\S+/i' => 'key=[REDACTED]',
            '/secret[=:]\s*\S+/i' => 'secret=[REDACTED]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $message = preg_replace($pattern, $replacement, $message);
        }

        return $message;
    }

    /**
     * Санитизация массива данных
     */
    private function sanitizeArray(array $data): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth', 'ftp_password'];
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArray($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = $this->sanitizeValue($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Санитизация значения
     */
    private function sanitizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return strlen($value) > 1000 ? substr($value, 0, 1000).'... [truncated]' : $value;
        }

        return $value;
    }
}
