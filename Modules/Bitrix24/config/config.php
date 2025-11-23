<?php
return [
    'name' => 'Bitrix24',
    'webhook' => [
        'url' => env('B24_WEBHOOK_URL'),
        'timeout' => 30,
    ],
    'entities' => [
        'deal' => [
            'fields' => [],
            'mapping' => []
        ]
    ]
];
