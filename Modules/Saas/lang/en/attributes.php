<?php

return [
    'tenants' => [
        'name' => 'Name',
        'legal_name' => 'Legal Name',
        'slug' => 'Slug',
        'domain' => 'Domain',
        'contact_name' => 'Contact Name',
        'contact_email' => 'Contact Email',
        'contact_phone' => 'Contact Phone',
        'settings' => 'Settings',
        'active_plan' => 'Active Plan',
        'is_active' => 'Active',
    ],
    'subscription_plans' => [
        'name' => 'Name',
        'code' => 'Code',
        'description' => 'Description',
        'billing_cycle' => 'Billing Cycle',
        'price' => 'Price',
        'currency' => 'Currency',
        'features' => 'Features',
        'limits' => 'Limits',
        'is_active' => 'Active',
    ],
    'tenant_subscriptions' => [
        'tenant_id' => 'Tenant',
        'subscription_plan_id' => 'Plan',
        'status' => 'Status',
        'starts_at' => 'Starts At',
        'ends_at' => 'Ends At',
        'trial_ends_at' => 'Trial Ends At',
        'cancelled_at' => 'Cancelled At',
        'overrides' => 'Overrides',
    ],
];
