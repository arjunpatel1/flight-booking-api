<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Performance Instrumentation
    |--------------------------------------------------------------------------
    |
    | This is intentionally disabled by default. Enable it temporarily in
    | production during controlled measurement windows, or permanently in
    | staging/load-test environments.
    |
    */

    'api' => [
        'enabled' => env('PERFORMANCE_API_TIMING_ENABLED', false),
        'log_slow_requests' => env('PERFORMANCE_LOG_SLOW_REQUESTS', true),
        'slow_request_ms' => (int) env('PERFORMANCE_SLOW_REQUEST_MS', 750),
        'sample_rate' => (float) env('PERFORMANCE_SAMPLE_RATE', 1.0),
        'include_headers' => env('PERFORMANCE_INCLUDE_TIMING_HEADERS', true),
        'log_query_fingerprints' => env('PERFORMANCE_LOG_QUERY_FINGERPRINTS', false),
        'query_fingerprint_limit' => (int) env('PERFORMANCE_QUERY_FINGERPRINT_LIMIT', 20),
        'paths' => array_filter(array_map(
            'trim',
            explode(',', env('PERFORMANCE_API_PATHS', 'api/v1/auth/*,api/v1/pos/*,api/v1/orders/*,api/v1/cart/*,api/v1/printers/*,api/v1/print-agents/*'))
        )),
    ],
];
