<?php

return [
    'name' => 'EnterpriseData',
    /*
    |--------------------------------------------------------------------------
    | Настройки обмена с 1С
    |--------------------------------------------------------------------------
    */

    'own_base_guid' => env('EXCHANGE_OWN_BASE_GUID', '00000000-0000-0000-0000-000000000000'),

    'exchange_plan_name' => env('EXCHANGE_PLAN_NAME', 'СинхронизацияДанныхЧерезУниверсальныйФормат'),

    'available_versions_sending' => [
        '1.11', '1.10', '1.8', '1.7', '1.6',
    ],

    'available_versions_receiving' => [
        '1.11', '1.10', '1.8', '1.7', '1.6',
    ],

    /*
    |--------------------------------------------------------------------------
    | Настройки безопасности
    |--------------------------------------------------------------------------
    */

    'security' => [
        'max_file_size' => env('EXCHANGE_MAX_FILE_SIZE', 50 * 1024 * 1024), // 50MB
        'max_objects_per_batch' => env('EXCHANGE_MAX_OBJECTS_PER_BATCH', 100),
        'allowed_object_types' => [
            'Документ.*',
            'Справочник.*',
            'РегистрСведений.*',
            'РегистрНакопления.*',
            'Константа.*',
        ],
        'file_lock_timeout' => env('EXCHANGE_FILE_LOCK_TIMEOUT', 300), // 5 минут
    ],

    /*
    |--------------------------------------------------------------------------
    | Настройки производительности
    |--------------------------------------------------------------------------
    */

    'performance' => [
        'chunk_size' => env('EXCHANGE_CHUNK_SIZE', 50),
        'max_execution_time' => env('EXCHANGE_MAX_EXECUTION_TIME', 300),
        'memory_limit' => env('EXCHANGE_MEMORY_LIMIT', '256M'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Настройки логирования
    |--------------------------------------------------------------------------
    */

    'logging' => [
        'channel' => env('EXCHANGE_LOG_CHANNEL', 'exchange'),
        'level' => env('EXCHANGE_LOG_LEVEL', 'info'),
        'cleanup_days' => env('EXCHANGE_LOG_CLEANUP_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Настройки мониторинга
    |--------------------------------------------------------------------------
    */

    'monitoring' => [
        'enabled' => env('EXCHANGE_MONITORING_ENABLED', true),
        'slow_query_threshold' => env('EXCHANGE_SLOW_QUERY_THRESHOLD', 5.0), // секунды
        'alert_email' => env('EXCHANGE_ALERT_EMAIL'),
    ],
];
