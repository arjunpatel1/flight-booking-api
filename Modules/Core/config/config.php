<?php

use Modules\Core\Http\Middleware\InitializeAppLocaleMiddleware;

return [
    "enable_route_domain" => env('ENABLE_ROUTE_DOMAIN', false),
    'idempotency' => [
        'completed_retention_days' => (int) env('IDEMPOTENCY_RETENTION_DAYS', 30),
        'processing_retention_hours' => (int) env('IDEMPOTENCY_PROCESSING_RETENTION_HOURS', 24),
        'prune_batch_size' => (int) env('IDEMPOTENCY_PRUNE_BATCH_SIZE', 1000),
    ],
    'routes' => [
        "public" => [
            "domain" => env('PUBLIC_DOMAIN', 'localhost'),
            "namespace" => "Http\\Controllers",
            "middleware" => ['web'],
            "file" => "web.php"
        ],
        "api" => [
            "domain" => env('API_DOMAIN', 'api.localhost'),
            "prefix" => "api",
            "version" => "v1",
            "namespace" => "Http\\Controllers\\Api\\V1",
            "middleware" => [
                "checkInstalled",
                InitializeAppLocaleMiddleware::class,
                'api',
                'throttle:api',
                'auth',
                \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class,
            ],
            "file" => "api/v1.php"
        ],
    ]
];
