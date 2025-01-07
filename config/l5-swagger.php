<?php

return [
    'default' => 'default',
    'documentations' => [
        'default' => [
            'api' => [
                'title' => 'L5 Swagger UI',
            ],
            'routes' => [
                'api' => 'api/documentation', // Маршрут к Swagger UI
            ],
            'paths' => [
                'use_absolute_path' => env('L5_SWAGGER_USE_ABSOLUTE_PATH', true),
                'docs_json' => 'api-docs.json', // Файл документации JSON
                'docs_yaml' => 'api-docs.yaml', // Файл документации YAML
                'format_to_use_for_docs' => env('L5_FORMAT_TO_USE_FOR_DOCS', 'json'),
                'annotations' => [
                    base_path('app'), // Путь к аннотациям
                    base_path('Modules'), // Путь к аннотациям
                ],
            ],
        ],
    ],
    'defaults' => [
        'routes' => [
            'docs' => 'docs', // Основной маршрут для документации
            'oauth2_callback' => 'api/oauth2-callback', // Маршрут для OAuth2
            'middleware' => [
                'api' => [],
                'asset' => [],
                'docs' => [],
                'oauth2_callback' => [],
            ],
            'group_options' => [],
        ],
        'paths' => [
            'docs' => storage_path('api-docs'), // Путь к сгенерированным файлам
            'views' => base_path('resources/views/vendor/l5-swagger'), // Путь для экспорта представлений
            'base' => env('L5_SWAGGER_BASE_PATH', '/'), // Базовый путь API
            'swagger_ui_assets_path' => env('L5_SWAGGER_UI_ASSETS_PATH', 'vendor/swagger-api/swagger-ui/dist/'),
            'excludes' => [], // Исключаемые директории
        ],
        'scanOptions' => [
            'analyser' => null,
            'analysis' => null,
            'processors' => [],
            'pattern' => null,
            'exclude' => [],
            'open_api_spec_version' => env('L5_SWAGGER_OPEN_API_SPEC_VERSION', \L5Swagger\Generator::OPEN_API_DEFAULT_SPEC_VERSION),
        ],
        'securityDefinitions' => [
            'securitySchemes' => [],
        ],

        'generate_always' => env('L5_SWAGGER_GENERATE_ALWAYS', false), // Отключаем генерацию на продакшене
        'generate_yaml_copy' => env('L5_SWAGGER_GENERATE_YAML_COPY', false),
        'proxy' => false,
        'additional_config_url' => null,
        'operations_sort' => env('L5_SWAGGER_OPERATIONS_SORT', null),
        'validator_url' => null,
        'ui' => [
            'display' => [
                'doc_expansion' => env('L5_SWAGGER_UI_DOC_EXPANSION', 'none'), // Раскрытие документации
                'filter' => env('L5_SWAGGER_UI_FILTERS', true), // Фильтры
            ],
            'authorization' => [
                'persist_authorization' => env('L5_SWAGGER_UI_PERSIST_AUTHORIZATION', false),
                'oauth2' => [
                    'use_pkce_with_authorization_code_grant' => false,
                ],
            ],
        ],
        'constants' => [
            'L5_SWAGGER_CONST_HOST' => env('L5_SWAGGER_CONST_HOST', 'https://v2api.it.4media.ru'), // URL сервера
        ],
    ],
];
