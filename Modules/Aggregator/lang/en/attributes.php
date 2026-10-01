<?php

return [
    'integrations' => [
        'provider' => 'Provider',
        'name' => 'Name',
        'base_url' => 'Base URL',
        'credentials' => 'Credentials',
        'webhook_secret' => 'Webhook Secret',
        'is_active' => 'Active',
        'settings' => 'Settings',
        'auto_sync' => 'Auto order sync',
        'auto_menu_sync' => 'Auto menu sync',
        'auto_status_sync' => 'Auto status sync',
        'sync_direct_orders' => 'Push direct orders to provider',
        'webhook_processing' => 'Webhook processing',
        'retry_enabled' => 'Retry enabled',
        'auto_order_accept' => 'Auto order accept',
        'official_contract_verified' => 'Official contract verified',
    ],
    'outlet_mappings' => [
        'aggregator_integration_id' => 'Integration',
        'branch_id' => 'Branch',
        'external_outlet_id' => 'External Outlet ID',
        'external_outlet_name' => 'External Outlet Name',
        'is_active' => 'Active',
    ],
    'menu_mappings' => [
        'aggregator_integration_id' => 'Integration',
        'menu_id' => 'Menu',
        'external_menu_id' => 'External Menu ID',
        'sync_enabled' => 'Sync Enabled',
        'last_synced_at' => 'Last Synced At',
    ],

    'item_availability' => [
        'items' => 'Items',
        'items.*.product_id' => 'Product',
        'items.*.available' => 'Available',
    ],
];
