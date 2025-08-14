<?php

namespace Modules\EnterpriseData\app\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ExchangeApiMonitoringMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        // Логирование входящего запроса
        Log::channel('exchange')->info('API Request', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'content_length' => $request->header('Content-Length', 0),
        ]);

        $response = $next($request);

        // Логирование ответа
        $duration = microtime(true) - $startTime;

        Log::channel('exchange')->info('API Response', [
            'status_code' => $response->getStatusCode(),
            'duration_ms' => round($duration * 1000, 2),
            'response_size' => strlen($response->getContent()),
        ]);

        // Предупреждение о медленных запросах
        if ($duration > 5.0) {
            Log::channel('exchange')->warning('Slow API Request', [
                'url' => $request->fullUrl(),
                'duration_ms' => round($duration * 1000, 2),
            ]);
        }

        return $response;
    }
}
