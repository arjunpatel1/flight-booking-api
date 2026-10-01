<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Pulse Dashboard Configuration
    |--------------------------------------------------------------------------
    |
    | Configure custom dashboards and cards for Restaurant POS monitoring
    */
    'dashboards' => [
        'overview' => [
            'title' => 'POS Overview',
            'cards' => [
                'requests' => [
                    'class' => Laravel\Pulse\Livewire\Requests::class,
                    'title' => 'API Requests',
                    'cols' => 6,
                    'rows' => 4,
                ],
                'slow_requests' => [
                    'class' => Laravel\Pulse\Livewire\SlowRequests::class,
                    'title' => 'Slow Requests',
                    'cols' => 6,
                    'rows' => 4,
                ],
                'exceptions' => [
                    'class' => Laravel\Pulse\Livewire\Exceptions::class,
                    'title' => 'Exceptions',
                    'cols' => 12,
                    'rows' => 4,
                ],
                'servers' => [
                    'class' => Laravel\Pulse\Livewire\Servers::class,
                    'title' => 'Server Health',
                    'cols' => 6,
                    'rows' => 4,
                ],
                'queues' => [
                    'class' => Laravel\Pulse\Livewire\Queues::class,
                    'title' => 'Queue Status',
                    'cols' => 6,
                    'rows' => 4,
                ],
            ],
        ],
        'database' => [
            'title' => 'Database Performance',
            'cards' => [
                'slow_queries' => [
                    'class' => Laravel\Pulse\Livewire\SlowQueries::class,
                    'title' => 'Slow Queries',
                    'cols' => 12,
                    'rows' => 6,
                ],
            ],
        ],
        'jobs' => [
            'title' => 'Background Jobs',
            'cards' => [
                'jobs' => [
                    'class' => Laravel\Pulse\Livewire\Jobs::class,
                    'title' => 'Job Performance',
                    'cols' => 6,
                    'rows' => 4,
                ],
                'slow_jobs' => [
                    'class' => Laravel\Pulse\Livewire\SlowJobs::class,
                    'title' => 'Slow Jobs',
                    'cols' => 6,
                    'rows' => 4,
                ],
            ],
        ],
        'cache' => [
            'title' => 'Cache Performance',
            'cards' => [
                'cache' => [
                    'class' => Laravel\Pulse\Livewire\Cache::class,
                    'title' => 'Cache Interactions',
                    'cols' => 12,
                    'rows' => 4,
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert Configuration
    |--------------------------------------------------------------------------
    |
    | Configure alerts for critical metrics
    */
    'alerts' => [
        'enabled' => env('PULSE_ALERTS_ENABLED', true),
        'channels' => [
            'slack' => env('PULSE_SLACK_WEBHOOK_URL'),
            'email' => env('PULSE_ALERT_EMAIL'),
        ],
        'thresholds' => [
            'error_rate' => [
                'enabled' => true,
                'threshold' => 5, // 5% error rate
                'window' => '5 minutes',
            ],
            'slow_request_rate' => [
                'enabled' => true,
                'threshold' => 10, // 10% slow requests
                'window' => '5 minutes',
            ],
            'slow_query_rate' => [
                'enabled' => true,
                'threshold' => 5, // 5% slow queries
                'window' => '5 minutes',
            ],
            'queue_backlog' => [
                'enabled' => true,
                'threshold' => 100, // 100 jobs pending
                'window' => '1 minute',
            ],
            'exception_rate' => [
                'enabled' => true,
                'threshold' => 10, // 10 exceptions per minute
                'window' => '1 minute',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Metrics
    |--------------------------------------------------------------------------
    |
    | Configure custom metrics for Restaurant POS specific monitoring
    */
    'custom_metrics' => [
        'order_processing_time' => [
            'enabled' => true,
            'threshold' => 2000, // 2 seconds
            'description' => 'Order processing time in milliseconds',
        ],
        'payment_processing_time' => [
            'enabled' => true,
            'threshold' => 5000, // 5 seconds
            'description' => 'Payment processing time in milliseconds',
        ],
        'print_queue_size' => [
            'enabled' => true,
            'threshold' => 50, // 50 print jobs
            'description' => 'Print queue size',
        ],
        'voice_queue_size' => [
            'enabled' => true,
            'threshold' => 20, // 20 voice announcements
            'description' => 'Voice queue size',
        ],
    ],
];
