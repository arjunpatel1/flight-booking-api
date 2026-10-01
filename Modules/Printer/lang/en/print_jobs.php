<?php

return [
    'print_jobs' => 'Print Jobs',
    'print_job' => 'Print Job',
    'retry' => 'Retry',
    'retry_confirmation' => 'Retry this print job?',
    'success_jobs_cannot_be_retried' => 'Successful print jobs cannot be retried.',

    'summary' => [
        'total' => 'Total Jobs',
        'pending' => 'Pending',
        'success' => 'Printed',
        'failed' => 'Failed',
    ],

    'diagnostics' => [
        'title' => 'Printer Diagnostics',
        'active_printers' => 'Active Printers',
        'online_agents' => 'Online Agents',
        'offline_agents' => 'Offline Agents',
        'cash_drawer' => 'Cash Drawer',
        'barcode_scanner' => 'Barcode Scanner',
        'weighing_scale' => 'Weighing Scale',
        'failed_last_24h' => 'Failed 24h',
        'ready' => 'Ready',
        'needs_setup' => 'Needs setup',
    ],

    'table' => [
        'branch' => 'Branch',
        'order' => 'Order',
        'print_type' => 'Print',
        'printer_type' => 'Type',
        'printer_name' => 'Printer',
        'agent' => 'Agent',
        'pipeline' => 'Route Map',
        'paper_size' => 'Paper',
        'copies' => 'Copies',
        'status' => 'Status',
        'error_message' => 'Error',
        'completed_at' => 'Completed At',
    ],

    'pipeline' => [
        'completed' => 'Completed',
        'in_progress' => 'In Progress',
        'warning' => 'Warning',
        'failed' => 'Failed',
    ],

    'steps' => [
        'order' => 'Order payload',
        'route' => 'Printer route',
        'agent' => 'Agent target',
        'queue' => 'Queue history',
        'delivery' => 'Agent delivery',
    ],

    'tracking' => [
        'events' => 'Tracking',
        'route_source' => 'Route source',
        'claimed_by' => 'Claimed by',
        'lease_until' => 'Lease until',
    ],

    'stages' => [
        'queued' => 'Queued',
        'retry_queued' => 'Re-queued',
        'claimed' => 'Claimed',
        'success' => 'Printed',
        'failed' => 'Failed',
        'printer_config' => 'Printer configured',
        'no_printer' => 'No printer',
        'no_printable_products' => 'Nothing to print',
        'no_kitchen_printer' => 'No kitchen printer',
    ],

    'messages' => [
        'check_order_ok' => 'Order #:ref resolved.',
        'route_resolved' => 'Printer route resolved.',
        'route_missing' => 'No printer route found.',
        'route_no_match' => 'No printer route matched assignment, register or branch fallback.',
        'agent_selected' => 'Target agent :agent selected.',
        'agent_missing' => 'No explicit agent target on this job.',
        'agent_none' => 'No explicit online agent target was resolved.',
        'delivery_success' => 'Agent reported successful print.',
        'delivery_failed' => 'Agent reported print failure.',
        'delivery_claimed' => 'Claimed by :agent; waiting for report.',
        'delivery_waiting_agent' => 'Waiting for agent :agent pickup.',
        'delivery_waiting_any' => 'Waiting for any compatible agent pickup.',
    ],

    'live' => [
        'label' => 'Live updates',
        'on' => 'Live',
        'off' => 'Paused',
    ],

    'filters' => [
        'status' => 'Status',
    ],
];
