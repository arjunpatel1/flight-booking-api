<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => env('MAILGUN_SCHEME', 'https'),
        'inbound_domain' => env('MAILGUN_INBOUND_DOMAIN', 'myteknoland.in'),
        'webhook_signing_key' => env('MAILGUN_WEBHOOK_SIGNING_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'base_uri' => env('OPENAI_API_BASE_URI', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-3.5-turbo'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'push' => [
        'provider' => env('PUSH_PROVIDER', 'firebase'),
    ],

    'customer_otp' => [
        'channels' => array_values(array_filter(array_map('trim', explode(',', env('CUSTOMER_OTP_CHANNELS', 'whatsapp,email'))))),
        'whatsapp_template' => env('CUSTOMER_OTP_WHATSAPP_TEMPLATE', 'customer_login_otp'),
        'ttl_minutes' => (int) env('CUSTOMER_OTP_TTL_MINUTES', 5),
        'resend_seconds' => (int) env('CUSTOMER_OTP_RESEND_SECONDS', 60),
        'max_attempts' => (int) env('CUSTOMER_OTP_MAX_ATTEMPTS', 5),
        // Never enabled in production. This exists solely for deterministic
        // local/automated tests where sending an actual customer message is
        // neither useful nor desirable. Production needs all four explicit
        // values below, including an expiry, before this can activate.
        'test_enabled' => filter_var(env('CUSTOMER_OTP_TESTING_ENABLED', false), FILTER_VALIDATE_BOOL),
        'test_code' => env('CUSTOMER_OTP_TEST_CODE'),
        'test_recipients' => array_values(array_filter(array_map('trim', explode(',', env('CUSTOMER_OTP_TEST_RECIPIENTS', ''))))),
        'test_expires_at' => env('CUSTOMER_OTP_TEST_EXPIRES_AT'),
    ],

    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS'),
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'web_api_key' => env('FIREBASE_WEB_API_KEY'),
        'auth_domain' => env('FIREBASE_AUTH_DOMAIN'),
        'storage_bucket' => env('FIREBASE_STORAGE_BUCKET'),
        'messaging_sender_id' => env('FIREBASE_MESSAGING_SENDER_ID'),
        'app_id' => env('FIREBASE_APP_ID'),
        'measurement_id' => env('FIREBASE_MEASUREMENT_ID'),
        'vapid_key' => env('FIREBASE_VAPID_KEY'),
    ],

    'sso' => [
        'providers' => [
            'google' => [
                'enabled' => env('SSO_GOOGLE_ENABLED', false),
                'name' => 'Google',
                'client_id' => env('SSO_GOOGLE_CLIENT_ID'),
                'client_secret' => env('SSO_GOOGLE_CLIENT_SECRET'),
                'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url' => 'https://oauth2.googleapis.com/token',
                'userinfo_url' => 'https://www.googleapis.com/oauth2/v3/userinfo',
                'scopes' => ['openid', 'email', 'profile'],
            ],
            'microsoft' => [
                'enabled' => env('SSO_MICROSOFT_ENABLED', false),
                'name' => 'Microsoft',
                'client_id' => env('SSO_MICROSOFT_CLIENT_ID'),
                'client_secret' => env('SSO_MICROSOFT_CLIENT_SECRET'),
                'tenant' => env('SSO_MICROSOFT_TENANT', 'common'),
                'authorize_url' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize',
                'token_url' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token',
                'userinfo_url' => 'https://graph.microsoft.com/oidc/userinfo',
                'scopes' => ['openid', 'email', 'profile'],
            ],
        ],
    ],

];
