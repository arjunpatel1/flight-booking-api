<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Pulse Enabled
    |--------------------------------------------------------------------------
    |
    | Pulse records request, query, queue, cache, and exception metadata.
    | Enabled for production monitoring and observability.
    */
    'enabled' => env('PULSE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Pulse Dashboard Path
    |--------------------------------------------------------------------------
    |
    | Keep the dashboard path configurable so production can move it behind
    | a private/admin-only route without changing code.
    */
    'path' => env('PULSE_PATH', 'pulse'),

    /*
    |--------------------------------------------------------------------------
    | Pulse Middleware
    |--------------------------------------------------------------------------
    |
    | Use the package authorization middleware. AppServiceProvider defines
    | the viewPulse gate and restricts access to super admins.
    */
    'middleware' => [
        'web',
        'auth',
        Laravel\Pulse\Http\Middleware\Authorize::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pulse Ingestion
    |--------------------------------------------------------------------------
    |
    | Configure how Pulse ingests and stores data.
    */
    'ingest' => [
        'driver' => env('PULSE_INGEST_DRIVER', 'redis'),
        'trim' => [
            'lottery' => [1, 1000],
            'exceptions' => now()->subDays(7),
            'slow_queries' => now()->subDays(7),
            'slow_requests' => now()->subDays(7),
            'slow_jobs' => now()->subDays(7),
            'slow_outgoing_requests' => now()->subDays(7),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pulse Recorders
    |--------------------------------------------------------------------------
    |
    | Configure which recorders are enabled.
    */
    'recorders' => [
        Laravel\Pulse\Recorders\CacheInteractions::class => env('PULSE_CACHE_INTERACTIONS', true),
        Laravel\Pulse\Recorders\Exceptions::class => env('PULSE_EXCEPTIONS', true),
        // Laravel\Pulse\Recorders\Jobs::class => env('PULSE_JOBS', true),
        // Laravel\Pulse\Recorders\OutgoingRequests::class => env('PULSE_OUTGOING_REQUESTS', true),
        Laravel\Pulse\Recorders\Queues::class => env('PULSE_QUEUES', true),
        Laravel\Pulse\Recorders\Servers::class => env('PULSE_SERVERS', true),
        Laravel\Pulse\Recorders\SlowJobs::class => env('PULSE_SLOW_JOBS', true),
        Laravel\Pulse\Recorders\SlowOutgoingRequests::class => env('PULSE_SLOW_OUTGOING_REQUESTS', true),
        Laravel\Pulse\Recorders\SlowQueries::class => env('PULSE_SLOW_QUERIES', true),
        Laravel\Pulse\Recorders\SlowRequests::class => env('PULSE_SLOW_REQUESTS', true),
        Laravel\Pulse\Recorders\UserJobs::class => env('PULSE_USER_JOBS', true),
        Laravel\Pulse\Recorders\UserRequests::class => env('PULSE_USER_REQUESTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pulse Performance Thresholds
    |--------------------------------------------------------------------------
    |
    | Configure the thresholds for performance monitoring.
    */
    'thresholds' => [
        'slow_job' => env('PULSE_SLOW_JOB_THRESHOLD', 5000),
        'slow_outgoing_request' => env('PULSE_SLOW_OUTGOING_REQUEST_THRESHOLD', 5000),
        'slow_query' => env('PULSE_SLOW_QUERY_THRESHOLD', 1000),
        'slow_request' => env('PULSE_SLOW_REQUEST_THRESHOLD', 1000),
    ],
];
