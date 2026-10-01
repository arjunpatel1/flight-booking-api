<?php

return [
    // Operations must opt in before any tenant setting can activate a provider.
    // This must be explicitly enabled before the adapter can make even sandbox
    // requests. Production has an additional independent kill switch below.
    'integration_enabled' => (bool) env('DELIVERY_INTEGRATION_ENABLED', false),
    'sandbox_enabled' => (bool) env('DELIVERY_UENGAGE_SANDBOX_ENABLED', false),
    'booking_enabled' => (bool) env('DELIVERY_UENGAGE_BOOKING_ENABLED', false),
    'cancellation_enabled' => (bool) env('DELIVERY_UENGAGE_CANCELLATION_ENABLED', false),
    'webhook_enabled' => (bool) env('DELIVERY_UENGAGE_WEBHOOK_ENABLED', false),
    // Store only SHA-256 of the random Bearer token configured in uEngage.
    'webhook_token_hash' => env('DELIVERY_UENGAGE_WEBHOOK_TOKEN_HASH'),
    // v1.3 has no createTask idempotency contract. This is intentionally
    // unavailable to application code even if an environment value is set.
    'automatic_create_task_retry_enabled' => false,
    'provider' => env('DELIVERY_PROVIDER', 'uengage'),
    'queue' => env('DELIVERY_ASSIGNMENT_QUEUE', 'delivery'),
    'uengage' => [
        // Flash Open API v1.3. Production needs a separate operational release.
        'environment' => env('DELIVERY_UENGAGE_ENVIRONMENT', 'sandbox'),
        // Current Developer APIs console (Flash Open API 1.0 staging).
        'sandbox_base_url' => 'https://riderapi-staging.uengage.in',
        'production_base_url' => 'https://open-api.flash.uengage.in',
        'production_enabled' => (bool) env('DELIVERY_UENGAGE_PRODUCTION_ENABLED', false),
        'connect_timeout' => 5,
        'timeout' => 15,
    ],
];
