<?php

use Modules\Printer\Enum\PrinterPaperSize;
use Modules\User\Enums\{PermissionAction as Action};

return [
    'engine' => env('PRINT_ENGINE', 'image'),

    'permissions' => [
        "printers" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        "print_agents" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        "print_jobs" => [Action::Index, Action::Retry],
        "printer_assignments" => [Action::Index, Action::Edit],
    ],

    'media_profiles' => [
        PrinterPaperSize::Paper58mm->value => [
            'paper_width_mm' => 58,
            'pixel_width' => 219.21,
            'thermal_dot_width' => 384,
        ],

        PrinterPaperSize::Paper80mm->value => [
            'paper_width_mm' => 80,
            'pixel_width' => 302.36,
            'thermal_dot_width' => 576,
        ],
    ],

    'fonts' => [
        'cairo' => [
            'regular' => storage_path('fonts/Cairo-Regular.ttf'),
            'bold' => storage_path('fonts/Cairo-Bold.ttf'),
        ]
    ],

    'diagnostics' => [
        'agent_offline_after_minutes' => 5,
        'hardware_capabilities' => [
            'cash_drawer',
            'barcode_scanner',
            'weighing_scale',
        ],
    ],

    'queue' => [
        'delivery_lease_seconds' => 120,
        'agent_batch_size' => (int) env('PRINT_AGENT_BATCH_SIZE', 10),
        'high_priority' => env('PRINT_HIGH_PRIORITY_QUEUE', env('REDIS_QUEUE', 'default')),
        'agent_heartbeat_seconds' => (int) env('PRINT_AGENT_HEARTBEAT_SECONDS', 60),
        'empty_poll_cache_seconds' => (int) env('PRINT_AGENT_EMPTY_POLL_CACHE_SECONDS', 5),
        'polling_fallback_delay_seconds' => (int) env('PRINT_POLLING_FALLBACK_DELAY_SECONDS', 1),
        'render_lock_seconds' => (int) env('PRINT_RENDER_LOCK_SECONDS', 45),
        'completed_duplicate_grace_seconds' => (int) env('PRINT_COMPLETED_DUPLICATE_GRACE_SECONDS', 12),
        'stale_lease_grace_seconds' => (int) env('PRINT_STALE_LEASE_GRACE_SECONDS', 10),
        'fail_stale_pending_minutes' => (int) env('PRINT_FAIL_STALE_PENDING_MINUTES', 0),
        'recovery_limit' => (int) env('PRINT_RECOVERY_LIMIT', 100),
    ],

    'agent' => [
        'transport' => env('PRINT_TRANSPORT', 'polling'),
        'idle_sleep_seconds' => (float) env('PRINT_AGENT_IDLE_SLEEP_SECONDS', 2.0),
        'max_idle_sleep_seconds' => (float) env('PRINT_AGENT_MAX_IDLE_SLEEP_SECONDS', 30.0),
        'default_poll_wait_seconds' => (float) env('PRINT_AGENT_DEFAULT_POLL_WAIT_SECONDS', 20.0),
        'long_poll_seconds' => (float) env('PRINT_AGENT_LONG_POLL_SECONDS', 20.0),
        'long_poll_interval_ms' => (int) env('PRINT_AGENT_LONG_POLL_INTERVAL_MS', 150),
        'long_poll_retry_after_seconds' => (float) env('PRINT_AGENT_LONG_POLL_RETRY_AFTER_SECONDS', 1.0),
    ],

    'browser' => [
        'chrome_path' => env('BROWSERSHOT_CHROME_PATH', env('CHROME_PATH')),
        'device_scale_factor' => (int) env('PRINT_BROWSER_DEVICE_SCALE_FACTOR', 2),
        'timeout_seconds' => (int) env('PRINT_BROWSER_TIMEOUT_SECONDS', 30),
        'cache_minutes' => (int) env('PRINT_RENDER_CACHE_MINUTES', 30),
        'wait_for_network_idle' => (bool) env('PRINT_BROWSER_WAIT_FOR_NETWORK_IDLE', false),
        'embed_fonts' => (bool) env('PRINT_BROWSER_EMBED_FONTS', false),
        'safe_cut_margin_mm' => (float) env('PRINT_BROWSER_SAFE_CUT_MARGIN_MM', 6),
    ],

    'escpos' => [
        'fast_text_types' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('PRINT_ESC_POS_FAST_TEXT_TYPES', 'kitchen,waiter,bill,invoice'))
        ))),
        'experimental_invoice_enabled' => env('PRINT_ENGINE', 'image') === 'escpos',
        'paper58_columns' => (int) env('PRINT_ESC_POS_PAPER58_COLUMNS', 32),
        'paper80_columns' => (int) env('PRINT_ESC_POS_PAPER80_COLUMNS', 48),
        'trailing_feed_lines' => (int) env('PRINT_ESC_POS_TRAILING_FEED_LINES', 5),
        // Receipt totals must fully clear the cutter before the cut command.
        // This feed is appended below the content; it does not add top margin.
        'receipt_trailing_feed_lines' => (int) env('PRINT_ESC_POS_RECEIPT_TRAILING_FEED_LINES', 12),
        // Low-cost Bluetooth printer firmware may execute GS V before its text
        // buffer has mechanically cleared the cutter. Twelve line feeds keep the
        // final KOT item above the cut without adding margin before the ticket.
        'kitchen_trailing_feed_lines' => (int) env('PRINT_ESC_POS_KITCHEN_TRAILING_FEED_LINES', 12),
        'logo_enabled' => (bool) env('PRINT_ESC_POS_LOGO_ENABLED', true),
        'logo_max_width_dots' => (int) env('PRINT_ESC_POS_LOGO_MAX_WIDTH_DOTS', 192),
        'logo_max_height_dots' => (int) env('PRINT_ESC_POS_LOGO_MAX_HEIGHT_DOTS', 160),
    ],

    'spooler' => [
        'wait_for_completion' => (bool) env('PRINT_SPOOLER_WAIT_FOR_COMPLETION', false),
        'default_margins' => [
            'top' => (int) env('PRINT_SPOOLER_MARGIN_TOP', 0),
            'right' => (int) env('PRINT_SPOOLER_MARGIN_RIGHT', 0),
            'bottom' => (int) env('PRINT_SPOOLER_MARGIN_BOTTOM', 4),
            'left' => (int) env('PRINT_SPOOLER_MARGIN_LEFT', 0),
        ],
    ],
];
