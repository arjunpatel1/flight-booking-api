<?php

use Modules\User\Enums\{PermissionAction as Action};

return [
    'permissions' => [
        "payments" => [Action::Index, Action::Show]
    ],

    /*
     * Card-present payment terminal gateways. `default` is used when a payment
     * request sets a gateway without naming one. Each driver is only offered
     * when fully configured (see PaymentGatewayManager).
     */
    'gateways' => [
        'default' => env('PAYMENT_GATEWAY'), // e.g. 'pinelabs' or 'manual'

        // Pine Labs Plutus Smart — Cloud Based Integration.
        'pinelabs' => [
            'base_url' => env('PINELABS_BASE_URL'),
            'merchant_id' => env('PINELABS_MERCHANT_ID'),
            'store_id' => env('PINELABS_STORE_ID'),
            'client_id' => env('PINELABS_CLIENT_ID'),
            'security_token' => env('PINELABS_SECURITY_TOKEN'),
            'user_id' => env('PINELABS_USER_ID'),
            'poll_timeout_seconds' => (int) env('PINELABS_POLL_TIMEOUT', 60),
            'poll_interval_seconds' => (int) env('PINELABS_POLL_INTERVAL', 3),
            'http_timeout_seconds' => (int) env('PINELABS_HTTP_TIMEOUT', 30),
            'auto_cancel_minutes' => (int) env('PINELABS_AUTO_CANCEL_MINUTES', 5),
            'allow_reversal' => env('PINELABS_ALLOW_REVERSAL', true),
        ],

        // Razorpay Checkout / terminal-assisted capture.
        // The POS records a payment only after a Razorpay payment id is verified
        // and captured. Cash/offline payments do not depend on this gateway.
        'razorpay' => [
            'base_url' => env('RAZORPAY_BASE_URL', 'https://api.razorpay.com/v1'),
            'key_id' => env('RAZORPAY_KEY_ID'),
            'key_secret' => env('RAZORPAY_KEY_SECRET'),
            'capture_authorized' => filter_var(env('RAZORPAY_CAPTURE_AUTHORIZED', true), FILTER_VALIDATE_BOOLEAN),
            'require_signature' => filter_var(env('RAZORPAY_REQUIRE_SIGNATURE', true), FILTER_VALIDATE_BOOLEAN),
            'http_timeout_seconds' => (int) env('RAZORPAY_HTTP_TIMEOUT', 20),
            'allow_refund' => filter_var(env('RAZORPAY_ALLOW_REFUND', false), FILTER_VALIDATE_BOOLEAN),
            'refund_speed' => env('RAZORPAY_REFUND_SPEED', 'normal'),
            // Partner Auth credentials belong to NexDine. A tenant stores only
            // its activated Razorpay client account id.
            'partner_auth_enabled' => filter_var(env('RAZORPAY_PARTNER_AUTH_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        ],
    ],
];
