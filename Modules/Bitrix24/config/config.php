<?php

return [
    'name' => 'Bitrix24',

    'webhook' => [
        'url' => env('B24_WEBHOOK_URL'),
        'timeout' => env('B24_TIMEOUT', 30),
    ],

    'sync' => [
        // Размер порции для чтения из БД (для экономии памяти)
        'chunk_size' => env('B24_CHUNK_SIZE', 500),

        // Макс. запросов в секунду к B24 (2 = допустимо, 1 = консервативно)
        'requests_per_second' => env('B24_REQUESTS_PER_SECOND', 1),

        // Макс. попыток повтора
        'max_retries' => env('B24_MAX_RETRIES', 3),

        // Минимальная дата синхронизации счетов
        'min_invoice_date' => env('B24_MIN_INVOICE_DATE', '2025-11-01'),

        // Таймаут для зависших записей (минуты)
        'stale_timeout_minutes' => env('B24_STALE_TIMEOUT', 10),
    ],

    'entities' => [
        'deal' => [
            'fields' => [],
            'mapping' => [],
        ],
    ],
];
