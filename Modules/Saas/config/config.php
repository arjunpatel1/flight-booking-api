<?php

use Modules\User\Enums\PermissionAction as Action;

return [
    'company_mail' => [
        'brand_name' => env('SAAS_MAIL_BRAND_NAME', 'NexDine'),
        'logo_url' => env('SAAS_MAIL_LOGO_URL', env('APP_LOGO_URL')),
        'primary_color' => env('SAAS_MAIL_PRIMARY_COLOR', '#F57C00'),
        // Platform-owned identities available only in the global SaaS console.
        // Never accept an arbitrary From address from an API request.
        'senders' => [
            'general' => [
                'name' => env('SAAS_MAIL_GENERAL_NAME', 'NexDine'),
                'address' => env('SAAS_MAIL_GENERAL_ADDRESS', 'nexdine@myteknoland.in'),
            ],
            'support' => [
                'name' => env('SAAS_MAIL_SUPPORT_NAME', 'NexDine Support'),
                'address' => env('SAAS_MAIL_SUPPORT_ADDRESS', 'support@myteknoland.in'),
            ],
            'marketing' => [
                'name' => env('SAAS_MAIL_MARKETING_NAME', 'NexDine Marketing'),
                'address' => env('SAAS_MAIL_MARKETING_ADDRESS', 'marketing@myteknoland.in'),
            ],
        ],
    ],
    'security' => [
        'require_platform_mfa' => (bool) env('SAAS_REQUIRE_PLATFORM_MFA', false),
        'require_tenant_admin_mfa' => (bool) env('SAAS_REQUIRE_TENANT_ADMIN_MFA', false),
        'suspicious_login_window_minutes' => (int) env('SAAS_SUSPICIOUS_LOGIN_WINDOW_MINUTES', 30),
        'suspicious_login_ip_threshold' => (int) env('SAAS_SUSPICIOUS_LOGIN_IP_THRESHOLD', 3),
    ],
    'root_domain' => env('SAAS_ROOT_DOMAIN', 'nexdine.test'),
    'tenant_server_ips' => array_values(array_filter(array_map('trim', explode(',', env('SAAS_TENANT_SERVER_IPS', ''))))),
    'central_domains' => array_values(array_filter(array_map('trim', explode(',', env(
        'SAAS_CENTRAL_DOMAINS',
        'localhost,127.0.0.1,api.localhost,api.nexdine.test,nexdine.test'
    ))))),
    'permissions' => [
        'tenants' => [
            Action::Index,
            Action::Show,
            Action::Create,
            Action::Edit,
            Action::Destroy,
        ],
        'subscription_plans' => [
            Action::Index,
            Action::Show,
            Action::Create,
            Action::Edit,
            Action::Destroy,
        ],
        'tenant_subscriptions' => [
            Action::Index,
            Action::Show,
            Action::Create,
            Action::Edit,
            Action::Destroy,
        ],
        'saas' => [
            Action::Index,
            Action::Create,
            Action::Edit,
            Action::Manage,
            Action::Monitoring,
        ],
    ],
    'default_features' => [
        'branches',
        'users',
        'pos_registers',
        'orders_per_month',
        'waiter_app',
        'aggregator_integrations',
        'whatsapp_messages',
        // Every system Starter tenant can use the NexDine-managed WhatsApp
        // ordering path. Provider credentials and number assignment remain
        // explicit platform-admin actions, so this entitlement alone never
        // exposes a number or secret to a restaurant.
        'whatsapp_ordering',
        'whatsapp_managed_api',
        'whatsapp_human_handoff',
    ],
    'enterprise_features' => [
        'pos',
        'kitchen',
        'qr_ordering',
        'waiter_app',
        'customer_display',
        'inventory',
        'analytics',
        'reports',
        'payments',
        'printer',
        'whatsapp',
        'sms',
        'ai',
        'online_ordering',
        'reservations',
        'coupons',
        'loyalty',
        'gift_cards',
        'invoices',
        'tax',
        'delivery',
        'aggregator',
        // Explicit, deny-by-default Customer App capabilities. Listing them in
        // the plan catalogue does not grant them to any existing subscription.
        'customer_app',
        'customer_app_build',
        'customer_app_aab',
        // WhatsApp ordering is split so plans can allow restaurant-owned
        // credentials, NexDine-managed credentials, or both independently.
        'whatsapp_ordering',
        'whatsapp_bring_your_own_api',
        'whatsapp_managed_api',
        'whatsapp_order_payments',
        'whatsapp_order_automation',
        'whatsapp_human_handoff',
        // Data imports are explicitly assigned per plan or per restaurant.
        // Existing tenants remain denied until the platform enables access.
        'data_imports',
    ],
    'customer_app' => [
        'manifest_ttl_seconds' => (int) env('SAAS_CUSTOMER_APP_MANIFEST_TTL_SECONDS', 300),
        'clock_skew_seconds' => (int) env('SAAS_CUSTOMER_APP_CLOCK_SKEW_SECONDS', 30),
        'session_ttl_seconds' => (int) env('SAAS_CUSTOMER_APP_SESSION_TTL_SECONDS', 86400),
        'api_origin' => env('SAAS_CUSTOMER_APP_API_ORIGIN', env('SAAS_PUBLIC_API_BASE_URL', env('APP_URL'))),
        'content_requests_per_minute' => (int) env('SAAS_CUSTOMER_APP_CONTENT_REQUESTS_PER_MINUTE', 60),
        // Values may be PEM text or an absolute file path. The private key is
        // backend-only and must be supplied through secret management.
        'manifest_private_key' => env('SAAS_CUSTOMER_APP_MANIFEST_PRIVATE_KEY'),
        'manifest_public_key' => env('SAAS_CUSTOMER_APP_MANIFEST_PUBLIC_KEY'),
    ],
    'customer_app_build' => [
        // Phase 2 is a control plane only. A dedicated builder may opt in in a
        // later phase; Laravel must never pretend a local build is running.
        'worker_connected' => (bool) env('SAAS_CUSTOMER_APP_BUILD_WORKER_CONNECTED', false),
        'source_commit' => env('SAAS_CUSTOMER_APP_SOURCE_COMMIT'),
        'require_splash' => (bool) env('SAAS_CUSTOMER_APP_REQUIRE_SPLASH', false),
        'artifact_disk' => env('SAAS_CUSTOMER_APP_ARTIFACT_DISK', 'local'),
        'requests_per_hour' => (int) env('SAAS_CUSTOMER_APP_BUILD_REQUESTS_PER_HOUR', 10),
        // Dedicated CI/build-runner credentials only. Laravel coordinates the
        // build; it never invokes Flutter, Gradle, or tenant supplied commands.
        'worker_token' => env('SAAS_CUSTOMER_APP_BUILD_WORKER_TOKEN'),
        'lease_seconds' => (int) env('SAAS_CUSTOMER_APP_BUILD_LEASE_SECONDS', 1800),
        // Heartbeats keep a healthy worker lease alive, but may never extend a
        // single build indefinitely. CI must retry from a clean worker after
        // this absolute wall-clock limit.
        'max_runtime_seconds' => (int) env('SAAS_CUSTOMER_APP_BUILD_MAX_RUNTIME_SECONDS', 2400),
        'max_attempts' => (int) env('SAAS_CUSTOMER_APP_BUILD_MAX_ATTEMPTS', 3),
        'max_active_per_tenant' => (int) env('SAAS_CUSTOMER_APP_BUILD_MAX_ACTIVE_PER_TENANT', 1),
        'max_artifact_bytes' => (int) env('SAAS_CUSTOMER_APP_BUILD_MAX_ARTIFACT_BYTES', 250 * 1024 * 1024),
        'retention_days' => (int) env('SAAS_CUSTOMER_APP_BUILD_RETENTION_DAYS', 30),
        'repository' => env('SAAS_CUSTOMER_APP_SOURCE_REPOSITORY'),
        'flutter_version' => env('SAAS_CUSTOMER_APP_FLUTTER_VERSION'),
        'android_sdk_version' => env('SAAS_CUSTOMER_APP_ANDROID_SDK_VERSION'),
        // Public SHA-256 certificate fingerprint (hex, no separators). The
        // private keystore remains exclusively in the build runner secret store.
        'signer_sha256' => env('SAAS_CUSTOMER_APP_SIGNER_SHA256'),
        'supports_aab' => (bool) env('SAAS_CUSTOMER_APP_BUILD_SUPPORTS_AAB', false),
    ],
    'customer_app_links' => [
        // Public identifier from the Apple developer account. Association
        // output fails closed when it is absent; never guess this value.
        'apple_team_id' => env('SAAS_CUSTOMER_APP_APPLE_TEAM_ID'),
        'invite_path' => '/customer-app/group',
    ],
    'server_automation' => [
        'enabled' => env('SAAS_SERVER_AUTOMATION_ENABLED', false),
        'allow_apply' => env('SAAS_SERVER_AUTOMATION_ALLOW_APPLY', false),
        // When server automation is explicitly enabled, domain + certificate
        // provisioning is part of normal tenant creation unless separately
        // disabled. This prevents a successfully-created tenant landing on the
        // nginx default 404 or an invalid certificate.
        'automatic_tenant_ssl' => env(
            'SAAS_AUTOMATIC_TENANT_SSL',
            env('SAAS_SERVER_AUTOMATION_ENABLED', false)
        ),
        'ssl_email' => env('SAAS_SSL_EMAIL', env('MAIL_FROM_ADDRESS')),
        // Apply mode is also constrained by /etc/nexdine/tenant-ssl.conf,
        // which is root-owned and cannot be changed by the web process.
        'tenant_domain_suffix' => env('SAAS_TENANT_DOMAIN_SUFFIX'),
        'use_sudo' => env('SAAS_SERVER_AUTOMATION_USE_SUDO', false),
        'timeout' => env('SAAS_SERVER_AUTOMATION_TIMEOUT', 300),
        'web_server' => env('SAAS_SERVER_AUTOMATION_WEB_SERVER', 'apache'),
        'frontend_root' => env('SAAS_FRONTEND_ROOT', base_path('../dist')),
        'api_root' => env('SAAS_API_ROOT', public_path()),
        'scripts' => [
            'apache' => env('SAAS_SERVER_AUTOMATION_APACHE_SCRIPT', base_path('Modules/Saas/deploy/apache-saas-ssl.sh')),
            'nginx' => env('SAAS_SERVER_AUTOMATION_NGINX_SCRIPT', base_path('Modules/Saas/deploy/nginx-saas-ssl.sh')),
            'tenant_nginx' => env('SAAS_TENANT_NGINX_SSL_SCRIPT', base_path('Modules/Saas/deploy/nginx-tenant-ssl.sh')),
        ],
        'processes' => [
            'supervisorctl' => env('SAAS_SUPERVISORCTL_PATH', '/usr/bin/supervisorctl'),
            'reverb_program' => env('SAAS_REVERB_SUPERVISOR_PROGRAM', ''),
            'worker_program' => env('SAAS_WORKER_SUPERVISOR_PROGRAM', ''),
        ],
    ],
    'deployment' => [
        'enabled' => (bool) env('SAAS_DEPLOYMENT_ENABLED', false),
        'allow_apply' => (bool) env('SAAS_DEPLOYMENT_ALLOW_APPLY', false),
        'repository_root' => env('SAAS_DEPLOYMENT_ROOT'),
        'allowed_branches' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('SAAS_DEPLOYMENT_ALLOWED_BRANCHES', 'main,staging'))
        ))),
        'key_fingerprint' => env('SAAS_DEPLOYMENT_KEY_FINGERPRINT'),
        'script' => env('SAAS_DEPLOYMENT_SCRIPT', base_path('Modules/Saas/deploy/release-control.sh')),
        'timeout' => (int) env('SAAS_DEPLOYMENT_TIMEOUT', 900),
        'repositories' => [
            'backend' => [
                'label' => 'Backend API',
                'root' => env('SAAS_BACKEND_DEPLOYMENT_ROOT', env('SAAS_DEPLOYMENT_ROOT')),
                'post_deploy_script' => env('SAAS_BACKEND_POST_DEPLOY_SCRIPT', env('SAAS_POST_DEPLOY_SCRIPT')),
            ],
            'frontend' => [
                'label' => 'Frontend Web',
                'root' => env('SAAS_FRONTEND_DEPLOYMENT_ROOT'),
                'post_deploy_script' => env('SAAS_FRONTEND_POST_DEPLOY_SCRIPT'),
            ],
        ],
        'terminal_enabled' => (bool) env('SAAS_TERMINAL_ENABLED', false),
        'terminal_timeout' => (int) env('SAAS_TERMINAL_TIMEOUT', 30),
    ],
    'restore' => [
        'execution_enabled' => env('SAAS_RESTORE_EXECUTION_ENABLED', false),
        'allowed_environments' => array_values(array_filter(array_map('trim', explode(',', env('SAAS_RESTORE_ALLOWED_ENVIRONMENTS', 'local,staging'))))),
    ],
    'billing' => [
        'gst_rate' => (float) env('SAAS_BILLING_GST_RATE', 18),
        'gateway' => env('SAAS_BILLING_GATEWAY', 'manual'),
        'currency' => env('SAAS_BILLING_CURRENCY', 'INR'),
        'due_days' => (int) env('SAAS_BILLING_DUE_DAYS', 7),
        'trial_days' => (int) env('SAAS_BILLING_TRIAL_DAYS', 90),
        'grace_days' => (int) env('SAAS_BILLING_GRACE_DAYS', 7),
        'trial_reminder_days' => array_map('intval', array_values(array_filter(array_map('trim', explode(',', env('SAAS_TRIAL_REMINDER_DAYS', '30,15,7,3,1')))))),
        'razorpay' => [
            'key_id' => env('SAAS_RAZORPAY_KEY_ID'),
            'key_secret' => env('SAAS_RAZORPAY_KEY_SECRET'),
            'webhook_secret' => env('SAAS_RAZORPAY_WEBHOOK_SECRET'),
        ],
        'stripe' => [
            'secret' => env('SAAS_STRIPE_SECRET'),
            'webhook_secret' => env('SAAS_STRIPE_WEBHOOK_SECRET'),
            'success_url' => env('SAAS_STRIPE_SUCCESS_URL', env('APP_URL').'/admin/saas?billing=success'),
            'cancel_url' => env('SAAS_STRIPE_CANCEL_URL', env('APP_URL').'/admin/saas?billing=cancelled'),
        ],
    ],
    'self_service' => [
        'enabled' => env('SAAS_SELF_SERVICE_ENABLED', false),
        /*
         * legacy   — a signup provisions a tenant immediately (original behaviour)
         * pipeline — a signup creates an onboarding request for the approval
         *            pipeline, so it appears in the operator queue
         *
         * Defaults to legacy so existing installations are unaffected.
         */
        'mode' => env('SELF_SERVICE_MODE', 'legacy'),
        'requires_payment' => env('SAAS_SELF_SERVICE_REQUIRES_PAYMENT', false),
        // Public URL consumed by mobile and desktop clients. This can differ
        // from Laravel's internal /api/v1 route prefix when a reverse proxy
        // exposes the API at /v1.
        'public_api_base_url' => rtrim(
            env('SAAS_PUBLIC_API_BASE_URL', rtrim(env('APP_URL', ''), '/').'/api/v1'),
            '/'
        ),
        'captcha_required' => env('SAAS_SELF_SERVICE_CAPTCHA_REQUIRED', true),
        'captcha_secret' => env('SAAS_SELF_SERVICE_CAPTCHA_SECRET'),
        'default_plan' => env('SAAS_SELF_SERVICE_DEFAULT_PLAN', 'starter'),
        'trial_days' => (int) env('SAAS_SELF_SERVICE_TRIAL_DAYS', env('SAAS_BILLING_TRIAL_DAYS', 90)),
        'invite_days' => (int) env('SAAS_ONBOARDING_INVITE_DAYS', 7),
        'activation_ttl_minutes' => (int) env('SAAS_ACTIVATION_TTL_MINUTES', 60),
        'downloads' => [
            'waiter_app' => env('SAAS_DOWNLOAD_WAITER_APP_URL'),
            'windows_agent' => env('SAAS_DOWNLOAD_WINDOWS_AGENT_URL', 'https://nexdine.myteknoland.in/downloads/agent/NexDine-Agent-Setup.exe'),
            'windows_agent_enterprise' => env('SAAS_DOWNLOAD_WINDOWS_AGENT_ENTERPRISE_URL', 'https://nexdine.myteknoland.in/downloads/agent/NexDine-Agent-Enterprise.zip'),
            'kitchen_app' => env('SAAS_DOWNLOAD_KITCHEN_APP_URL'),
            'owner_app' => env('SAAS_DOWNLOAD_OWNER_APP_URL'),
            'customer_display' => env('SAAS_DOWNLOAD_CUSTOMER_DISPLAY_URL'),
        ],
    ],
    'alerts' => [
        'recipient' => env('SAAS_ALERT_RECIPIENT'),
        'channels' => array_values(array_filter(array_map('trim', explode(',', env('SAAS_ALERT_CHANNELS', 'email,in_app'))))),
    ],
    'realtime' => [
        /*
         * Realtime channel naming version (Phase 2.5).
         *   v1 — bare-id channels only (current; the safe default)
         *   v2 — dual-broadcast: every event emitted on BOTH the v1 channel and
         *        its tenant-namespaced v2 sibling, so clients migrate with no
         *        downtime. Retire v1 only when telemetry shows zero v1 subs.
         */
        'channel_version' => env('REALTIME_CHANNEL_VERSION', 'v1'),
        'enabled' => env('SAAS_REALTIME_ENABLED', true),
        'websocket_priority' => env('SAAS_REALTIME_WEBSOCKET_PRIORITY', true),
        'reconnect_max_attempts' => (int) env('SAAS_REALTIME_RECONNECT_MAX_ATTEMPTS', 3),
        'fallback_enabled' => env('SAAS_REALTIME_FALLBACK_ENABLED', true),
        'fallback_active_interval_seconds' => (int) env('SAAS_REALTIME_FALLBACK_ACTIVE_INTERVAL_SECONDS', 15),
        'fallback_idle_interval_seconds' => (int) env('SAAS_REALTIME_FALLBACK_IDLE_INTERVAL_SECONDS', 120),
        'fallback_inactivity_threshold_minutes' => (int) env('SAAS_REALTIME_FALLBACK_INACTIVITY_MINUTES', 15),
        'fallback_max_backoff_seconds' => (int) env('SAAS_REALTIME_FALLBACK_MAX_BACKOFF_SECONDS', 120),
        'recovery_probe_interval_seconds' => (int) env('SAAS_REALTIME_RECOVERY_PROBE_INTERVAL_SECONDS', 60),
        'admin_alert_enabled' => env('SAAS_REALTIME_ADMIN_ALERT_ENABLED', true),
        'admin_alert_after_attempts' => (int) env('SAAS_REALTIME_ADMIN_ALERT_AFTER_ATTEMPTS', 3),
        'incident_window_minutes' => (int) env('SAAS_REALTIME_INCIDENT_WINDOW_MINUTES', 15),
    ],
    'support' => [
        'handoff_ttl_seconds' => (int) env('SAAS_SUPPORT_HANDOFF_TTL_SECONDS', 60),
        'session_minutes' => (int) env('SAAS_SUPPORT_SESSION_MINUTES', 30),
    ],
    'provisioning' => [
        'queue' => env('SAAS_PROVISIONING_QUEUE', 'default'),
        'recovery_stale_minutes' => (int) env('SAAS_PROVISIONING_RECOVERY_STALE_MINUTES', 10),
        'recovery_limit' => (int) env('SAAS_PROVISIONING_RECOVERY_LIMIT', 25),
    ],
    'queues' => [
        'provisioning' => env('SAAS_PROVISIONING_QUEUE', 'provisioning'),
        'notifications' => env('SAAS_NOTIFICATIONS_QUEUE', 'notifications'),
        'delivery' => env('SAAS_DELIVERY_QUEUE', 'delivery'),
        'assets' => env('SAAS_ASSETS_QUEUE', 'assets'),
        'monitoring' => env('SAAS_MONITORING_QUEUE', 'monitoring'),
    ],
    'waiter_app_build' => [
        'webhook_url' => env('SAAS_WAITER_APP_BUILD_WEBHOOK_URL'),
        'webhook_token' => env('SAAS_WAITER_APP_BUILD_WEBHOOK_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Onboarding pipeline
    |--------------------------------------------------------------------------
    |
    | How a new onboarding request is treated when nobody specifies.
    |
    |   auto   — provision as soon as payment clears, no human step
    |   manual — an operator reviews and approves
    |   sales  — the sales owner reviews and approves
    |
    | The default is `manual` on purpose: auto-provisioning on payment is the
    | riskier posture, and a platform should opt into it deliberately rather
    | than inherit it.
    |
    */
    'onboarding' => [
        'default_approval_mode' => env('SAAS_ONBOARDING_APPROVAL_MODE', 'manual'),
        // Requests older than this that never got paid are surfaced as stale
        // in the operator queue rather than sitting invisible forever.
        'stale_after_hours' => (int) env('SAAS_ONBOARDING_STALE_AFTER_HOURS', 72),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant workspace
    |--------------------------------------------------------------------------
    |
    | Everything a restaurant owner needs to self-serve: what to download, what
    | to read, and who to contact. This is a catalogue, not business logic — it
    | is intentionally config-driven so operations can publish a new build or a
    | new guide by editing env/config, with no migration and no deploy of new
    | code. `TenantWorkspaceService` reads it and never writes to it.
    |
    */
    'workspace' => [
        // Download Center. `url` null means "not published yet" and the UI shows
        // the entry as coming soon rather than offering a dead link.
        'artifacts' => [
            [
                'key' => 'waiter_app',
                'name' => 'NexDine Waiter App',
                'summary' => 'Take orders at the table on any Android phone or tablet.',
                'platform' => 'Android',
                'icon' => 'tabler-device-mobile',
                'url' => env('SAAS_DOWNLOAD_WAITER_APP_URL'),
                'version' => env('SAAS_WAITER_APP_VERSION'),
                'released_at' => env('SAAS_WAITER_APP_RELEASED_AT'),
                'min_os' => env('SAAS_WAITER_APP_MIN_ANDROID', 'Android 8.0'),
                'size' => env('SAAS_WAITER_APP_SIZE'),
                'checksum' => env('SAAS_WAITER_APP_SHA256'),
                'release_notes' => env('SAAS_WAITER_APP_RELEASE_NOTES'),
                'status' => 'available',
            ],
            [
                'key' => 'windows_agent',
                'name' => 'NexDine Print Agent',
                'summary' => 'Connects your kitchen and billing printers to NexDine.',
                'platform' => 'Windows 10/11 (64-bit)',
                'icon' => 'tabler-printer',
                'url' => env('SAAS_DOWNLOAD_WINDOWS_AGENT_URL', 'https://nexdine.myteknoland.in/downloads/agent/NexDine-Agent-Setup.exe'),
                'version' => env('SAAS_WINDOWS_AGENT_VERSION', '1.0.2'),
                'released_at' => env('SAAS_WINDOWS_AGENT_RELEASED_AT'),
                'min_os' => 'Windows 10 (64-bit)',
                'size' => env('SAAS_WINDOWS_AGENT_SIZE', '292 MB'),
                'checksum' => env('SAAS_WINDOWS_AGENT_SHA256'),
                'release_notes' => env('SAAS_WINDOWS_AGENT_RELEASE_NOTES', 'Secure pairing and server printing, automatic light/dark UI, reliable upgrades, and corrected database permissions.'),
                'status' => 'available',
            ],
            [
                'key' => 'windows_agent_enterprise',
                'name' => 'NexDine Agent — Restricted PC',
                'summary' => 'Direct-install package for PCs where security policy blocks temporary installer execution.',
                'platform' => 'Windows 10/11 (64-bit)',
                'icon' => 'tabler-shield-lock',
                'url' => env('SAAS_DOWNLOAD_WINDOWS_AGENT_ENTERPRISE_URL', 'https://nexdine.myteknoland.in/downloads/agent/NexDine-Agent-Enterprise.zip'),
                'version' => env('SAAS_WINDOWS_AGENT_VERSION', '1.0.2'),
                'released_at' => env('SAAS_WINDOWS_AGENT_RELEASED_AT'),
                'min_os' => 'Windows 10 (64-bit)',
                'size' => env('SAAS_WINDOWS_AGENT_ENTERPRISE_SIZE'),
                'checksum' => env('SAAS_WINDOWS_AGENT_ENTERPRISE_SHA256'),
                'release_notes' => 'Extract the ZIP and run Install-NexDine-Agent.cmd as Administrator. No installer payload runs from the Windows temporary directory.',
                'status' => 'available',
            ],
            [
                'key' => 'printer_utility',
                'name' => 'Printer Setup Utility',
                'summary' => 'Detects printers and prints a test receipt.',
                'platform' => 'Windows 10/11 (64-bit)',
                'icon' => 'tabler-tool',
                'url' => env('SAAS_DOWNLOAD_PRINTER_UTILITY_URL'),
                'version' => env('SAAS_PRINTER_UTILITY_VERSION'),
                'released_at' => env('SAAS_PRINTER_UTILITY_RELEASED_AT'),
                'min_os' => 'Windows 10 (64-bit)',
                'size' => env('SAAS_PRINTER_UTILITY_SIZE'),
                'checksum' => env('SAAS_PRINTER_UTILITY_SHA256'),
                'release_notes' => null,
                'status' => 'available',
            ],
            [
                'key' => 'kitchen_app',
                'name' => 'Kitchen Display',
                'summary' => 'Live kitchen order screen.',
                'platform' => 'Android',
                'icon' => 'tabler-tools-kitchen-2',
                'url' => env('SAAS_DOWNLOAD_KITCHEN_APP_URL'),
                'version' => env('SAAS_KITCHEN_APP_VERSION'),
                'released_at' => null,
                'min_os' => env('SAAS_KITCHEN_APP_MIN_ANDROID', 'Android 9.0'),
                'size' => null,
                'checksum' => env('SAAS_KITCHEN_APP_SHA256'),
                'release_notes' => null,
                'status' => env('SAAS_KITCHEN_APP_STATUS', 'coming_soon'),
            ],
            [
                'key' => 'customer_display',
                'name' => 'Customer Display',
                'summary' => 'Second screen showing the running bill to the guest.',
                'platform' => 'Android / Windows',
                'icon' => 'tabler-device-tv',
                'url' => env('SAAS_DOWNLOAD_CUSTOMER_DISPLAY_URL'),
                'version' => env('SAAS_CUSTOMER_DISPLAY_VERSION'),
                'released_at' => null,
                'min_os' => null,
                'size' => null,
                'checksum' => env('SAAS_CUSTOMER_DISPLAY_SHA256'),
                'release_notes' => null,
                'status' => env('SAAS_CUSTOMER_DISPLAY_STATUS', 'coming_soon'),
            ],
        ],

        // Documentation Center. Grouped, searchable client-side.
        'documentation' => [
            [
                'key' => 'getting_started',
                'title' => 'Getting Started',
                'icon' => 'tabler-rocket',
                'articles' => [
                    ['title' => 'Set up your restaurant in 30 minutes', 'url' => env('SAAS_DOC_GETTING_STARTED'), 'minutes' => 8, 'type' => 'guide'],
                    ['title' => 'Understanding branches and floors', 'url' => env('SAAS_DOC_BRANCHES'), 'minutes' => 5, 'type' => 'guide'],
                    ['title' => 'Adding your first staff members', 'url' => env('SAAS_DOC_STAFF'), 'minutes' => 4, 'type' => 'guide'],
                ],
            ],
            [
                'key' => 'waiter_app',
                'title' => 'Waiter App',
                'icon' => 'tabler-device-mobile',
                'articles' => [
                    ['title' => 'Installing and activating the app', 'url' => env('SAAS_DOC_WAITER_INSTALL'), 'minutes' => 6, 'type' => 'guide'],
                    ['title' => 'Taking your first order', 'url' => env('SAAS_DOC_WAITER_ORDER'), 'minutes' => 5, 'type' => 'video'],
                ],
            ],
            [
                'key' => 'printer_setup',
                'title' => 'Printer Setup',
                'icon' => 'tabler-printer',
                'articles' => [
                    ['title' => 'Installing the Print Agent', 'url' => env('SAAS_DOC_PRINTER_AGENT'), 'minutes' => 7, 'type' => 'guide'],
                    ['title' => 'Assigning printers to kitchen stations', 'url' => env('SAAS_DOC_PRINTER_ASSIGN'), 'minutes' => 5, 'type' => 'guide'],
                    ['title' => 'Printer not printing — what to check', 'url' => env('SAAS_DOC_PRINTER_TROUBLESHOOT'), 'minutes' => 6, 'type' => 'troubleshooting'],
                ],
            ],
            [
                'key' => 'kitchen',
                'title' => 'Kitchen',
                'icon' => 'tabler-tools-kitchen-2',
                'articles' => [
                    ['title' => 'Kitchen order flow and KOT', 'url' => env('SAAS_DOC_KITCHEN_FLOW'), 'minutes' => 6, 'type' => 'guide'],
                    ['title' => 'Voice announcements', 'url' => env('SAAS_DOC_KITCHEN_VOICE'), 'minutes' => 4, 'type' => 'guide'],
                ],
            ],
            [
                'key' => 'orders',
                'title' => 'Orders',
                'icon' => 'tabler-receipt-2',
                'articles' => [
                    ['title' => 'Order types: dine-in, takeaway, delivery', 'url' => env('SAAS_DOC_ORDER_TYPES'), 'minutes' => 5, 'type' => 'guide'],
                    ['title' => 'Splitting and merging bills', 'url' => env('SAAS_DOC_ORDER_SPLIT'), 'minutes' => 4, 'type' => 'guide'],
                ],
            ],
            [
                'key' => 'billing',
                'title' => 'Billing',
                'icon' => 'tabler-credit-card',
                'articles' => [
                    ['title' => 'Taking payments and settling bills', 'url' => env('SAAS_DOC_BILLING_PAYMENTS'), 'minutes' => 5, 'type' => 'guide'],
                    ['title' => 'Your NexDine subscription and invoices', 'url' => env('SAAS_DOC_BILLING_SUBSCRIPTION'), 'minutes' => 4, 'type' => 'guide'],
                ],
            ],
            [
                'key' => 'reports',
                'title' => 'Reports',
                'icon' => 'tabler-chart-histogram',
                'articles' => [
                    ['title' => 'Daily sales and settlement reports', 'url' => env('SAAS_DOC_REPORTS_SALES'), 'minutes' => 5, 'type' => 'guide'],
                    ['title' => 'GST reporting', 'url' => env('SAAS_DOC_REPORTS_GST'), 'minutes' => 6, 'type' => 'guide'],
                ],
            ],
            [
                'key' => 'troubleshooting',
                'title' => 'Troubleshooting',
                'icon' => 'tabler-lifebuoy',
                'articles' => [
                    ['title' => 'App cannot connect to the server', 'url' => env('SAAS_DOC_TROUBLE_CONNECT'), 'minutes' => 5, 'type' => 'troubleshooting'],
                    ['title' => 'Orders not reaching the kitchen', 'url' => env('SAAS_DOC_TROUBLE_KOT'), 'minutes' => 5, 'type' => 'troubleshooting'],
                    ['title' => 'Frequently asked questions', 'url' => env('SAAS_DOC_FAQ'), 'minutes' => 10, 'type' => 'faq'],
                ],
            ],
        ],

        // Remote support. Anything null is simply not offered in the UI.
        'support' => [
            'email' => env('SAAS_SUPPORT_EMAIL'),
            'phone' => env('SAAS_SUPPORT_PHONE'),
            'whatsapp' => env('SAAS_SUPPORT_WHATSAPP'),
            'business_hours' => env('SAAS_SUPPORT_HOURS', 'Mon–Sat, 9:00 AM – 9:00 PM IST'),
            'timezone' => env('SAAS_SUPPORT_TIMEZONE', 'Asia/Kolkata'),
            'book_demo_url' => env('SAAS_SUPPORT_BOOK_DEMO_URL'),
            'remote_assistance_url' => env('SAAS_SUPPORT_REMOTE_URL'),
            'screen_share_guide_url' => env('SAAS_SUPPORT_SCREEN_SHARE_URL'),
            'status_page_url' => env('SAAS_SUPPORT_STATUS_PAGE_URL'),
            'live_chat_enabled' => env('SAAS_SUPPORT_LIVE_CHAT_ENABLED', false),
        ],

        // Product announcements shown on the tenant dashboard.
        'announcements' => [],

        'activation' => [
            // Human-typeable key length, excluding separators. Grouped in fours.
            'key_length' => (int) env('SAAS_ACTIVATION_KEY_LENGTH', 16),
            'group_size' => 4,
        ],

        /*
         * Welcome email / WhatsApp fired after a restaurant activates.
         *
         * Disabled by default on purpose: enabling it sends real messages to
         * real restaurant owners. Turn on only once the templates and sender
         * identity are approved.
         */
        'welcome_notifications' => [
            'email_enabled' => env('SAAS_WELCOME_EMAIL_ENABLED', false),
            'whatsapp_enabled' => env('SAAS_WELCOME_WHATSAPP_ENABLED', false),
        ],
    ],
];
