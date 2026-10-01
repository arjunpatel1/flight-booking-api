<?php

return [
    'providers' => [
        'swiggy' => 'Swiggy',
        'zomato' => 'Zomato',
        'ondc' => 'ONDC',
        'magicpin' => 'Magicpin',
        'dunzo' => 'Dunzo',
        'porter' => 'Porter',
        'blinkit' => 'Blinkit',
        'uber_eats' => 'Uber Eats',
    ],
    'sync_statuses' => [
        'pending' => 'Pending',
        'processing' => 'Processing',
        'success' => 'Success',
        'failed' => 'Failed',
        'retrying' => 'Retrying',
        'cancelled' => 'Cancelled',
        'ignored' => 'Ignored',
        'skipped' => 'Skipped',
    ],
    'sync_types' => [
        'order' => 'Order',
        'menu' => 'Menu',
        'status' => 'Status',
        'webhook' => 'Webhook',
    ],
];
