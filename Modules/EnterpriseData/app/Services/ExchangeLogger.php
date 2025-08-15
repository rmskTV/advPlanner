<?php

namespace Modules\EnterpriseData\app\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\EnterpriseData\app\Models\ExchangeFtpConnector;
use Modules\EnterpriseData\app\Models\ExchangeLog;
use Modules\EnterpriseData\app\ValueObjects\ExchangeResult;

class ExchangeLogger
{
    private const SENSITIVE_FIELDS = [
        'password', 'ftp_password', 'token', 'secret', 'key', 'auth',
        'authorization', 'cookie', 'session', 'csrf_token',
    ];

    private const MAX_CONTEXT_SIZE = 1000; // Максимальный размер контекста в символах

    private const MAX_MESSAGE_LENGTH = 500; // Максимальная длина сообщения

    public function logExchangeStart(ExchangeFtpConnector $connector, string $direction): void
    {
        $context = $this->buildSecureContext([
            'connector_id' => $connector->id,
            'connector_name' => $connector->foreign_base_name,
            'direction' => $direction,
            'ftp_host' => parse_url($connector->ftp_path, PHP_URL_HOST),
            'exchange_format' => $connector->exchange_format,
            'started_at' => Carbon::now()->toISOString(),
        ]);

        Log::info(
            "Exchange started: {$direction} for connector {$connector->foreign_base_name}",
            $context
        );
    }

    public function logExchangeEnd(
        ExchangeFtpConnector $connector,
        string $direction,
        ExchangeResult $result
    ): void {
        $level = $result->success ? 'info' : 'error';
        $duration = $result->getDuration();

        $context = $this->buildSecureContext([
            'connector_id' => $connector->id,
            'connector_name' => $connector->foreign_base_name,
            'direction' => $direction,
            'success' => $result->success,
            'processed_messages' => $result->processedMessages,
            'processed_objects' => $result->processedObjects,
            'duration_seconds' => $duration,
            'error_count' => count($result->errors),
            'warning_count' => count($result->warnings),
            'completed_at' => Carbon::now()->toISOString(),
        ]);

        // Добавляем первые несколько ошибок (без чувствительных данных)
        if (! empty($result->errors)) {
            $context['sample_errors'] = $this->sanitizeErrors(array_slice($result->errors, 0, 3));
        }

        $message = $result->success
            ? "Exchange completed successfully: {$direction} for {$connector->foreign_base_name}"
            : "Exchange failed: {$direction} for {$connector->foreign_base_name}";

        Log::info($message, $context);

        // Предупреждение о медленном обмене
        if ($duration && $duration > 60) {
            $this->logSlowExchange($connector, $direction, $duration);
        }
    }

    public function logMessageProcessing(
        string $fileName,
        int $objectsCount,
        ?ExchangeFtpConnector $connector = null
    ): void {
        $context = $this->buildSecureContext([
            'file_name' => basename($fileName), // Только имя файла, без пути
            'objects_count' => $objectsCount,
            'connector_id' => $connector?->id,
            'processed_at' => Carbon::now()->toISOString(),
        ]);

        Log::info(
            "Processing message file: {$fileName} with {$objectsCount} objects",
            $context
        );
    }

    public function logFileOperation(
        string $operation,
        string $fileName,
        ExchangeFtpConnector $connector,
        bool $success = true,
        ?string $error = null
    ): void {
        $level = $success ? 'info' : 'error';

        $context = $this->buildSecureContext([
            'operation' => $operation,
            'file_name' => basename($fileName),
            'connector_id' => $connector->id,
            'ftp_host' => parse_url($connector->ftp_path, PHP_URL_HOST),
            'success' => $success,
            'timestamp' => Carbon::now()->toISOString(),
        ]);

        if (! $success && $error) {
            $context['error'] = $this->sanitizeMessage($error);
        }

        $message = $success
            ? "File {$operation} successful: {$fileName}"
            : "File {$operation} failed: {$fileName}";

        Log::info($message, $context);
    }

    public function logObjectProcessing(
        string $objectType,
        string $operation,
        int $count,
        ?ExchangeFtpConnector $connector = null,
        array $additionalContext = []
    ): void {
        $context = $this->buildSecureContext(array_merge([
            'object_type' => $objectType,
            'operation' => $operation,
            'count' => $count,
            'connector_id' => $connector?->id,
            'processed_at' => Carbon::now()->toISOString(),
        ], $additionalContext));

        Log::info(
            "Object processing: {$operation} {$count} objects of type {$objectType}",
            $context
        );
    }

    public function logError(string $message, array $context = []): void
    {
        $sanitizedMessage = $this->sanitizeMessage($message);
        $secureContext = $this->buildSecureContext($context);

        Log::error($sanitizedMessage, $secureContext);
    }

    public function logWarning(string $message, array $context = []): void
    {
        $sanitizedMessage = $this->sanitizeMessage($message);
        $secureContext = $this->buildSecureContext($context);

        Log::warning($sanitizedMessage, $secureContext);
    }

    public function logSecurityEvent(string $event, array $context = []): void
    {
        $secureContext = $this->buildSecureContext(array_merge($context, [
            'security_event' => true,
            'timestamp' => Carbon::now()->toISOString(),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]));

        Log::warning("Security event: {$event}", $secureContext);
    }

    public function logPerformanceMetric(
        string $metric,
        float $value,
        string $unit = 'ms',
        array $context = []
    ): void {
        $secureContext = $this->buildSecureContext(array_merge($context, [
            'metric' => $metric,
            'value' => $value,
            'unit' => $unit,
            'timestamp' => Carbon::now()->toISOString(),
        ]));

        Log::info("Performance metric: {$metric} = {$value}{$unit}", $secureContext);
    }

    public function getExchangeHistory(
        ExchangeFtpConnector $connector,
        int $limit = 100
    ): Collection {
        return ExchangeLog::where('connector_id', $connector->id)
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(function ($log) {
                // Удаляем чувствительные данные из истории
                $logData = $log->toArray();
                unset($logData['errors']); // Ошибки могут содержать чувствительную информацию

                return $logData;
            });
    }

    public function getExchangeStatistics(
        ExchangeFtpConnector $connector,
        Carbon $from,
        Carbon $to
    ): array {
        $logs = ExchangeLog::where('connector_id', $connector->id)
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $successful = $logs->where('status', 'completed');
        $failed = $logs->where('status', 'failed');

        return [
            'total_exchanges' => $logs->count(),
            'successful_exchanges' => $successful->count(),
            'failed_exchanges' => $failed->count(),
            'total_objects' => $successful->sum('objects_count'),
            'success_rate' => $logs->count() > 0 ? round(($successful->count() / $logs->count()) * 100, 2) : 0,
            'average_duration' => $successful->avg('duration_seconds'),
            'period' => [
                'from' => $from->toISOString(),
                'to' => $to->toISOString(),
            ],
        ];
    }

    public function logConnectionTest(
        ExchangeFtpConnector $connector,
        bool $success,
        ?string $error = null,
        ?float $responseTime = null
    ): void {
        $context = $this->buildSecureContext([
            'connector_id' => $connector->id,
            'connector_name' => $connector->foreign_base_name,
            'ftp_host' => parse_url($connector->ftp_path, PHP_URL_HOST),
            'ftp_port' => $connector->ftp_port,
            'success' => $success,
            'response_time_ms' => $responseTime ? round($responseTime * 1000, 2) : null,
            'tested_at' => Carbon::now()->toISOString(),
        ]);

        if (! $success && $error) {
            $context['error'] = $this->sanitizeMessage($error);
        }

        $level = $success ? 'info' : 'error';
        $message = $success
            ? "FTP connection test successful for {$connector->foreign_base_name}"
            : "FTP connection test failed for {$connector->foreign_base_name}";

        Log::info($message, $context);
    }

    private function buildSecureContext(array $context): array
    {
        // Удаляем чувствительные поля
        $sanitized = $this->removeSensitiveData($context);

        // Ограничиваем размер контекста
        $sanitized = $this->limitContextSize($sanitized);

        // Добавляем метаданные
        $sanitized['_meta'] = [
            'module' => 'EnterpriseData',
            'timestamp' => Carbon::now()->toISOString(),
            'memory_usage' => memory_get_usage(true),
        ];

        return $sanitized;
    }

    private function removeSensitiveData(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->removeSensitiveData($value);
            } elseif ($this->isSensitiveField($key)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private function isSensitiveField(string $fieldName): bool
    {
        $fieldName = strtolower($fieldName);

        foreach (self::SENSITIVE_FIELDS as $sensitiveField) {
            if (str_contains($fieldName, $sensitiveField)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeMessage(string $message): string
    {
        // Ограничиваем длину сообщения
        if (strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $message = substr($message, 0, self::MAX_MESSAGE_LENGTH).'... [truncated]';
        }

        // Удаляем потенциально чувствительную информацию из сообщения
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

    private function sanitizeErrors(array $errors): array
    {
        return array_map([$this, 'sanitizeMessage'], $errors);
    }

    private function limitContextSize(array $context): array
    {
        $json = json_encode($context);

        if (strlen($json) <= self::MAX_CONTEXT_SIZE) {
            return $context;
        }

        // Если контекст слишком большой, оставляем только основные поля
        $essentialFields = ['connector_id', 'direction', 'success', 'error_count', 'timestamp'];
        $limited = [];

        foreach ($essentialFields as $field) {
            if (isset($context[$field])) {
                $limited[$field] = $context[$field];
            }
        }

        $limited['_truncated'] = true;
        $limited['_original_size'] = strlen($json);

        return $limited;
    }

    private function logSlowExchange(
        ExchangeFtpConnector $connector,
        string $direction,
        float $duration
    ): void {
        $context = $this->buildSecureContext([
            'connector_id' => $connector->id,
            'connector_name' => $connector->foreign_base_name,
            'direction' => $direction,
            'duration_seconds' => $duration,
            'threshold_seconds' => 60,
        ]);

        Log::warning(
            "Slow exchange detected: {$direction} for {$connector->foreign_base_name} took {$duration}s",
            $context
        );
    }
}
