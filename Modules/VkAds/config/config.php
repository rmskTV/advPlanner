<?php

return [
    'name' => 'VkAds',

    // === VK ADS API НАСТРОЙКИ ===
    'api' => [
        'base_url' => env('VK_ADS_API_BASE_URL', 'https://ads.vk.com/api/v2/'),
        'timeout' => env('VK_ADS_API_TIMEOUT', 30),
        'retry_attempts' => env('VK_ADS_API_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('VK_ADS_API_RETRY_DELAY', 1000), // milliseconds
    ],

    // === RATE LIMITING ===
    'rate_limits' => [
        'requests_per_minute' => env('VK_ADS_RATE_LIMIT', 100),
        'requests_per_hour' => env('VK_ADS_RATE_LIMIT_HOUR', 5000),
        'burst_limit' => env('VK_ADS_BURST_LIMIT', 10),
    ],

    // === КЭШИРОВАНИЕ ===
    'cache' => [
        'statistics_ttl' => env('VK_ADS_STATS_CACHE_TTL', 3600), // 1 hour
        'campaigns_ttl' => env('VK_ADS_CAMPAIGNS_CACHE_TTL', 1800), // 30 minutes
        'accounts_ttl' => env('VK_ADS_ACCOUNTS_CACHE_TTL', 3600), // 1 hour
    ],

    // === СИНХРОНИЗАЦИЯ ===
    'sync' => [
        'enabled' => env('VK_ADS_SYNC_ENABLED', true),
        'statistics_sync_days' => env('VK_ADS_SYNC_STATISTICS_DAYS', 7),
        'auto_sync_interval' => env('VK_ADS_AUTO_SYNC_INTERVAL', 3600), // seconds
        'batch_size' => env('VK_ADS_SYNC_BATCH_SIZE', 50),
    ],

    // === ДОКУМЕНТООБОРОТ ===
    'documents' => [
        'storage_disk' => env('VK_ADS_DOCUMENTS_DISK', 'local'),
        'storage_path' => env('VK_ADS_DOCUMENTS_PATH', 'vk-ads/documents'),
        'act_number_prefix' => env('VK_ADS_ACT_PREFIX', 'VK-ACT'),
        'auto_generate_monthly_acts' => env('VK_ADS_AUTO_GENERATE_ACTS', true),
    ],

    // === УВЕДОМЛЕНИЯ ===
    'notifications' => [
        'enabled' => env('VK_ADS_NOTIFICATIONS_ENABLED', true),
        'budget_alert_threshold' => env('VK_ADS_BUDGET_ALERT_THRESHOLD', 0.9), // 90%
        'campaign_performance_alerts' => env('VK_ADS_PERFORMANCE_ALERTS', true),
        'email_reports' => env('VK_ADS_EMAIL_REPORTS', true),
    ],

    // === ОЧЕРЕДИ ===
    'queues' => [
        'sync' => env('VK_ADS_SYNC_QUEUE', 'vk-ads-sync'),
        'reports' => env('VK_ADS_REPORTS_QUEUE', 'vk-ads-reports'),
        'documents' => env('VK_ADS_DOCUMENTS_QUEUE', 'vk-ads-documents'),
    ],

    // === МЕТРИКИ ПО УМОЛЧАНИЮ ===
    'default_metrics' => [
        'impressions',
        'clicks',
        'spend',
        'ctr',
        'cpc',
        'cpm',
    ],

    // === ФОРМАТЫ ЭКСПОРТА ===
    'export_formats' => [
        'csv' => [
            'enabled' => true,
            'delimiter' => ',',
            'enclosure' => '"',
            'encoding' => 'UTF-8',
        ],
        'xlsx' => [
            'enabled' => env('VK_ADS_XLSX_EXPORT', false),
            'library' => 'phpspreadsheet', // or 'maatwebsite'
        ],
    ],

    // === ВАЛИДАЦИЯ ===
    'validation' => [
        'max_date_range_days' => env('VK_ADS_MAX_DATE_RANGE', 365),
        'max_export_records' => env('VK_ADS_MAX_EXPORT_RECORDS', 50000),
        'min_budget' => env('VK_ADS_MIN_BUDGET', 100), // рублей
    ],

    // === ЛОГИРОВАНИЕ ===
    'logging' => [
        'enabled' => env('VK_ADS_LOGGING_ENABLED', true),
        'level' => env('VK_ADS_LOG_LEVEL', 'info'),
        'api_calls' => env('VK_ADS_LOG_API_CALLS', false),
        'channel' => env('VK_ADS_LOG_CHANNEL', 'stack'),
    ],

    // === ИНТЕГРАЦИЯ С ACCOUNTING ===
    'accounting_integration' => [
        'enabled' => true,
        'auto_create_acts' => env('VK_ADS_AUTO_CREATE_ACTS', true),
        'act_generation_day' => env('VK_ADS_ACT_GENERATION_DAY', 1), // 1st day of month
        'vat_rate' => env('VK_ADS_DEFAULT_VAT_RATE', 20), // 20%
    ],
    // === КРЕАТИВЫ ===
    'creatives' => [
        'storage_disk' => env('VK_ADS_CREATIVES_DISK', 'public'),
        'storage_path' => env('VK_ADS_CREATIVES_PATH', 'vk-ads/creatives'),
        'max_file_size' => env('VK_ADS_MAX_FILE_SIZE', 90 * 1024 * 1024), // 90MB
        'allowed_video_formats' => ['mp4', 'mov'],
        'allowed_image_formats' => ['jpg', 'jpeg', 'png'],
        'auto_generate_variants' => env('VK_ADS_AUTO_GENERATE_VARIANTS', true),
    ],
    // === ОБЪЯВЛЕНИЯ ===
    'ads' => [
        'max_headline_length' => 100,
        'max_description_length' => 500,
        'default_call_to_action' => 'Узнать больше',
        'instream_max_duration' => 30, // секунд
        'instream_min_skip_offset' => 3,
        'instream_max_skip_offset' => 10,
    ],

    // === ВЕБХУКИ ===
    'webhooks' => [
        'enabled' => env('VK_ADS_WEBHOOKS_ENABLED', true),
        'secret' => env('VK_ADS_WEBHOOK_SECRET'),
        'endpoints' => [
            'campaign_status' => '/api/vk-ads/webhooks/campaign-status',
            'moderation_result' => '/api/vk-ads/webhooks/moderation-result',
        ],
    ],

    // === ВАЛИДАЦИЯ МЕДИАФАЙЛОВ ===
    'media_validation' => [
        'video' => [
            'instream' => [
                'aspect_ratios' => ['16:9'],
                'required_ratios' => ['16:9'],
                'optional_ratios' => ['9:16', '1:1'],
                'max_duration' => 30,
                'min_duration' => 5,
                'min_resolution' => ['width' => 1280, 'height' => 720],
            ],
            'banner' => [
                'aspect_ratios' => ['16:9', '1:1', '4:5', '9:16'],
                'max_duration' => 180,
                'min_duration' => 5,
            ],
        ],
        'image' => [
            'banner' => [
                'aspect_ratios' => ['16:9', '1:1', '4:5'],
                'min_resolution' => ['width' => 1200, 'height' => 675],
            ],
            'native' => [
                'aspect_ratios' => ['1:1', '4:5'],
                'min_resolution' => ['width' => 600, 'height' => 600],
            ],
        ],
    ],

];
