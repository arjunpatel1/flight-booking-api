<?php

use Modules\User\Enums\{PermissionAction as Action};

return [
    'partner' => [
        'clock_skew_seconds' => (int) env('PARTNER_API_CLOCK_SKEW_SECONDS', 300),
        'nonce_ttl_seconds' => (int) env('PARTNER_API_NONCE_TTL_SECONDS', 600),
        'max_body_bytes' => (int) env('PARTNER_API_MAX_BODY_BYTES', 1048576),
        'credential_grace_minutes' => (int) env('PARTNER_API_CREDENTIAL_GRACE_MINUTES', 30),
        'webhook_timeout_seconds' => (int) env('PARTNER_API_WEBHOOK_TIMEOUT_SECONDS', 10),
        'webhook_max_attempts' => (int) env('PARTNER_API_WEBHOOK_MAX_ATTEMPTS', 8),
    ],
    'permissions' => [
        "partner_integrations" => [
            Action::Index,
            Action::Show,
            Action::Create,
            Action::Edit,
            Action::Destroy,
            Action::Logs,
        ],
        "aggregator_integrations" => [
            Action::Index,
            Action::Show,
            Action::Create,
            Action::Edit,
            Action::Destroy,
            Action::Sync,
            Action::Logs,
        ],
    ],
];
