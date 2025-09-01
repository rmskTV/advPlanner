<?php

return [
    'name' => 'VkAds',

    'api' => [
        // ИСПРАВЛЕНО: актуальный VK Ads API
        'base_url' => env('VK_ADS_API_BASE_URL', 'https://ads.vk.com/api/v2/'),
        'timeout' => env('VK_ADS_API_TIMEOUT', 30),
    ],

    'client_id' => env('VK_ADS_CLIENT_ID'),
    'client_secret' => env('VK_ADS_CLIENT_SECRET'),

    'sync' => [
        'enabled' => env('VK_ADS_SYNC_ENABLED', true),
    ],

    'accounting_integration' => [
        'enabled' => true,
        'vat_rate' => env('VK_ADS_DEFAULT_VAT_RATE', 20),
    ],
];
