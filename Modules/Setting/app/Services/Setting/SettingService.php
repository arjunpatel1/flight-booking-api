<?php

namespace Modules\Setting\Services\Setting;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Currency\Currency;
use Modules\Currency\Enums\ExchangeService;
use Modules\Media\Models\Media;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Enums\WhatsAppProvider;
use Modules\Notification\Services\WhatsApp\WhatsAppTemplateCatalog;
use Modules\Setting\Enums\AutoRefreshMode;
use Modules\Setting\Enums\SettingSection;
use Modules\Setting\Models\Setting;
use Modules\Setting\Repositories\SettingRepository;
use Modules\Setting\Services\Appearance\AppearanceService;
use Modules\Support\Country;
use Modules\Support\DateFormats;
use Modules\Support\Enums\Day;
use Modules\Support\Enums\FilesystemDisk;
use Modules\Support\Enums\Frequency;
use Modules\Support\Enums\MailEncryptionProtocol;
use Modules\Support\Enums\Mailer;
use Modules\Support\Locale;
use Modules\Support\RTLDetector;
use Modules\Support\TimeFormats;
use Modules\Support\TimeZone;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;
use Storage;

class SettingService implements SettingServiceInterface
{
    public function __construct(private readonly AppearanceService $appearanceService) {}

    /** {@inheritDoc} */
    public function label(): string
    {
        return __('setting::settings.settings.setting');
    }

    /** {@inheritDoc} */
    public function getModel(): Setting
    {
        return new ($this->model());
    }

    /** {@inheritDoc} */
    public function model(): string
    {
        return Setting::class;
    }

    /** {@inheritDoc} */
    public function getAppSettings(bool $refresh = false, bool $includeLogoData = false): array
    {
        if ($refresh) {
            $this->refreshSettingBinding();
        }

        $tenantLogo = setting('restaurant_logo_url');
        $globalLogo = Media::getCacheMedia(setting('logo'))?->url;
        // A logo explicitly selected in Admin > Settings must win over the
        // provisioning-time URL. Otherwise the old tenant URL permanently
        // masks a newly uploaded logo and the UI falls back inconsistently.
        $resolvedLogo = $globalLogo ?: (filled($tenantLogo) ? $tenantLogo : asset('logo.svg'));

        $settings = [
            'supported_locales' => supportedLocaleKeys(),
            'supported_languages' => supportedLanguages(),
            'locale' => setting('default_locale'),
            'fallback_locale' => fallbackLocale(),
            'is_rtl' => RTLDetector::detect(),
            'timezone' => setting('default_timezone'),
            'currency' => setting('default_currency'),
            'app_name' => setting('app_name'),
            'demo_enabled' => (bool) config('app.demo_login_enabled'),
            'demo_accounts' => $this->getDemoAccounts(),
            'appearance' => $this->appearanceService->resolve(setting()->all()),
            'logo' => $resolvedLogo,
            'loader_logo' => Media::getCacheMedia(setting('loader_logo'))?->url ?: null,
            'logo_data_base64' => null,
            'loader_logo_data_base64' => null,
            'favicon' => Media::getCacheMedia(setting('favicon'))?->url ?: $resolvedLogo,
            'waiter_table_status_flow_enabled' => (bool) setting('waiter_table_status_flow_enabled', true),
        ];

        if ($includeLogoData) {
            $settings['logo_data_base64'] = getLogoBase64();
        }

        return $settings;
    }

    private function getDemoAccounts(): array
    {
        if (! (bool) config('app.demo_login_enabled') || ! Schema::hasTable('users')) {
            return [];
        }

        // Branch demo usernames are derived from the SAME pattern UserSeeder
        // uses — "{role->value}_branch_{branchId}" — so the login cards can never
        // drift from the seeded accounts. (The previous hardcoded list used
        // "admin_branch_1" while the seeder creates "admin_branch_branch_1",
        // because DefaultRole::AdminBranch->value === "admin_branch"; that mismatch
        // made the Admin (Branch) demo login disappear / fail after a fresh install.)
        $branchId = Schema::hasTable('branches')
            ? (Branch::query()->orderBy('id')->value('id') ?? 1)
            : 1;

        $branchRoles = [
            [DefaultRole::AdminBranch, 'user::auth.roles.admin_branch', 'tabler-user-cog'],
            [DefaultRole::Manager, 'user::auth.roles.manager', 'tabler-briefcase'],
            [DefaultRole::Cashier, 'user::auth.roles.cashier', 'tabler-cash-register'],
            [DefaultRole::Kitchen, 'user::auth.roles.kitchen', 'tabler-chef-hat'],
            [DefaultRole::Waiter, 'user::auth.roles.waiter', 'tabler-tools-kitchen-2'],
        ];

        // Super admin matches UserSeeder's always-seeded "admin" / 12345678 account.
        $accounts = [
            [
                'label' => 'user::auth.roles.super_admin',
                'icon' => 'tabler-shield-star',
                'identifier' => 'admin',
                'password' => '12345678',
            ],
        ];

        foreach ($branchRoles as [$role, $label, $icon]) {
            $accounts[] = [
                'label' => $label,
                'icon' => $icon,
                'identifier' => "{$role->value}_branch_{$branchId}",
                'password' => 'password',
            ];
        }

        $existingUsernames = User::query()
            ->whereIn('username', array_column($accounts, 'identifier'))
            ->pluck('username')
            ->all();

        return array_values(array_filter(
            $accounts,
            fn (array $account): bool => in_array($account['identifier'], $existingUsernames, true)
        ));
    }

    /** {@inheritDoc} */
    public function refreshSettingBinding(): void
    {
        app()->forgetInstance('setting');
        app()->singleton('setting', fn () => new SettingRepository(Setting::allCached()));
    }

    /** {@inheritDoc} */
    public function getSettings(SettingSection $section): array
    {
        $settings = setting()->all();
        $appNameSetting = $section === SettingSection::Application
            ? Setting::where('key', 'app_name')->first()
            : null;

        return match ($section) {
            SettingSection::General => [
                'supported_countries' => $settings['supported_countries'] ?? [],
                'default_country' => $settings['default_country'] ?? null,
                'supported_locales' => $settings['supported_locales'] ?? [config('app.locale')],
                'default_locale' => $settings['default_locale'] ?? config('app.locale'),
                'default_timezone' => $settings['default_timezone'] ?? config('app.timezone'),
                'default_date_format' => $settings['default_date_format'] ?? 'Y-m-d',
                'default_time_format' => $settings['default_time_format'] ?? 'H:i',
                'start_of_week' => $settings['start_of_week'] ?? null,
                'end_of_week' => $settings['end_of_week'] ?? null,
            ],
            SettingSection::Application => [
                'app_name' => $appNameSetting
                    ? unserialize($appNameSetting->getRawOriginal('payload'))
                    : setting('app_name'),
            ],
            SettingSection::Currency => [
                'supported_currencies' => $settings['supported_currencies'] ?? [],
                'default_currency' => $settings['default_currency'] ?? null,
                'currency_rate_exchange_service' => $settings['currency_rate_exchange_service'] ?? null,
                'forge_api_key' => $settings['forge_api_key'] ?? null,
                'fixer_access_key' => $settings['fixer_access_key'] ?? null,
                'currency_data_feed_api_key' => $settings['currency_data_feed_api_key'] ?? null,
                'auto_refresh_currency_rates' => $settings['auto_refresh_currency_rates'] ?? false,
                'auto_refresh_currency_rate_frequency' => $settings['auto_refresh_currency_rate_frequency'] ?? null,
            ],
            SettingSection::Mail => [
                'mail_mailer' => $settings['mail_mailer'] ?? Mailer::Smtp->value,
                'mail_from_address' => $settings['mail_from_address'] ?? config('mail.from.address'),
                'mail_from_name' => $settings['mail_from_name'] ?? $settings['app_name'],
                'mail_host' => $settings['mail_host'] ?? config('mail.mailers.smtp.host'),
                'mail_port' => $settings['mail_port'] ?? config('mail.mailers.smtp.port'),
                'mail_username' => $settings['mail_username'] ?? config('mail.mailers.smtp.username'),
                'mail_password' => $settings['mail_password'] ?? config('mail.mailers.smtp.password'),
                'mail_encryption' => $settings['mail_encryption'] ?? config('mail.mailers.smtp.encryption'),
            ],
            SettingSection::Filesystem => [
                'default_filesystem_disk' => $settings['default_filesystem_disk'],
                'private_filesystem_disk' => $settings['private_filesystem_disk'] ?? $settings['default_filesystem_disk'],
                'filesystem_s3_use_path_style_endpoint' => $settings['filesystem_s3_use_path_style_endpoint'] ?? null,
                'filesystem_s3_url' => $settings['filesystem_s3_url'] ?? null,
                'filesystem_s3_endpoint' => $settings['filesystem_s3_endpoint'] ?? null,
                'filesystem_s3_region' => $settings['filesystem_s3_region'] ?? null,
                'filesystem_s3_bucket' => $settings['filesystem_s3_bucket'] ?? null,
                'encryptable' => [
                    'filesystem_s3_key' => $settings['filesystem_s3_key'] ?? null,
                    'filesystem_s3_secret' => $settings['filesystem_s3_secret'] ?? null,
                ],
            ],
            SettingSection::Logo => [
                'logo' => Media::getCacheMedia($settings['logo'] ?? null, true),
                'invoice_logo' => Media::getCacheMedia($settings['invoice_logo'] ?? null, true),
                'favicon' => Media::getCacheMedia($settings['favicon'] ?? null, true),
                'loader_logo' => Media::getCacheMedia($settings['loader_logo'] ?? null, true),
            ],
            SettingSection::Kitchen => [
                'auto_refresh_enabled' => $settings['auto_refresh_enabled'] ?? false,
                'auto_refresh_mode' => $settings['auto_refresh_mode'] ?? null,
                'auto_refresh_interval' => $settings['auto_refresh_interval'] ?? null,
                'auto_refresh_pause_on_idle' => $settings['auto_refresh_pause_on_idle'] ?? false,
                'auto_refresh_idle_timeout' => $settings['auto_refresh_idle_timeout'] ?? null,
                'kitchen_sound_alert_enabled' => $settings['kitchen_sound_alert_enabled'] ?? false,
                'kitchen_print_with_payment_enabled' => $settings['kitchen_print_with_payment_enabled'] ?? false,
                'customer_order_auto_print_mode' => $settings['customer_order_auto_print_mode']
                    ?? ((bool) ($settings['printer_auto_kot_enabled'] ?? false) ? 'kot' : 'disabled'),
                'waiter_table_status_flow_enabled' => $settings['waiter_table_status_flow_enabled'] ?? true,
                'kitchen_delayed_order_minutes' => $settings['kitchen_delayed_order_minutes'] ?? 25,
            ],
            SettingSection::WhatsApp => [
                'whatsapp_enabled' => $settings['whatsapp_enabled'] ?? false,
                'whatsapp_provider' => $settings['whatsapp_provider'] ?? WhatsAppProvider::Msg91->value,
                'whatsapp_delivery_alerts_enabled' => $settings['whatsapp_delivery_alerts_enabled'] ?? false,
                'whatsapp_marketing_campaigns_enabled' => $settings['whatsapp_marketing_campaigns_enabled'] ?? false,
                'crm_inactive_customer_days' => $settings['crm_inactive_customer_days'] ?? 30,
                'crm_recent_customer_days' => $settings['crm_recent_customer_days'] ?? 7,
                'crm_high_value_customer_min_spend' => $settings['crm_high_value_customer_min_spend'] ?? 500,
                'crm_inactive_customer_automation_enabled' => $settings['crm_inactive_customer_automation_enabled'] ?? false,
                'crm_inactive_customer_automation_time' => $settings['crm_inactive_customer_automation_time'] ?? '10:00',
                'crm_inactive_customer_coupon_code' => $settings['crm_inactive_customer_coupon_code'] ?? null,
                'crm_inactive_customer_offer_title' => $settings['crm_inactive_customer_offer_title'] ?? null,
                'crm_inactive_customer_offer_valid_until' => $settings['crm_inactive_customer_offer_valid_until'] ?? null,
                'crm_birthday_offer_automation_enabled' => $settings['crm_birthday_offer_automation_enabled'] ?? false,
                'crm_birthday_offer_automation_time' => $settings['crm_birthday_offer_automation_time'] ?? '09:00',
                'crm_birthday_offer_coupon_code' => $settings['crm_birthday_offer_coupon_code'] ?? null,
                'crm_birthday_offer_title' => $settings['crm_birthday_offer_title'] ?? null,
                'crm_anniversary_offer_automation_enabled' => $settings['crm_anniversary_offer_automation_enabled'] ?? false,
                'crm_anniversary_offer_automation_time' => $settings['crm_anniversary_offer_automation_time'] ?? '09:00',
                'crm_anniversary_offer_coupon_code' => $settings['crm_anniversary_offer_coupon_code'] ?? null,
                'crm_anniversary_offer_title' => $settings['crm_anniversary_offer_title'] ?? null,
                // Existing tenants may never have run the notification seeder. Always
                // expose the complete catalog while preserving their provider IDs.
                'whatsapp_templates' => WhatsAppTemplateCatalog::merge($settings['whatsapp_templates'] ?? []),
                'whatsapp_msg91_marketing_reuse_utility' => $settings['whatsapp_msg91_marketing_reuse_utility'] ?? true,
                'whatsapp_meta_graph_version' => $settings['whatsapp_meta_graph_version'] ?? '23.0',
                'encryptable' => [
                    'whatsapp_nexmsg_account_id' => $settings['whatsapp_nexmsg_account_id'] ?? null,
                    'whatsapp_nexmsg_auth_key' => null,
                    'whatsapp_msg91_auth_key' => null,
                    'whatsapp_msg91_api_url' => $settings['whatsapp_msg91_api_url'] ?? config('notification.providers.msg91.api_url'),
                    'whatsapp_msg91_integrated_number' => $settings['whatsapp_msg91_integrated_number'] ?? null,
                    'whatsapp_msg91_sender_id' => $settings['whatsapp_msg91_sender_id'] ?? null,
                    'whatsapp_msg91_webhook_secret' => null,
                    'whatsapp_msg91_utility_auth_key' => null,
                    'whatsapp_msg91_utility_integrated_number' => $settings['whatsapp_msg91_utility_integrated_number'] ?? null,
                    'whatsapp_msg91_utility_webhook_secret' => null,
                    'whatsapp_msg91_marketing_auth_key' => null,
                    'whatsapp_msg91_marketing_integrated_number' => $settings['whatsapp_msg91_marketing_integrated_number'] ?? null,
                    'whatsapp_msg91_marketing_webhook_secret' => null,
                    'whatsapp_meta_access_token' => null,
                    'whatsapp_meta_phone_number_id' => $settings['whatsapp_meta_phone_number_id'] ?? null,
                    'whatsapp_meta_business_account_id' => $settings['whatsapp_meta_business_account_id'] ?? null,
                    'whatsapp_meta_webhook_secret' => null,
                    'whatsapp_twilio_sid' => $settings['whatsapp_twilio_sid'] ?? null,
                    'whatsapp_twilio_auth_token' => null,
                    'whatsapp_twilio_from' => $settings['whatsapp_twilio_from'] ?? null,
                ],
                'credentials_configured' => [
                    'nexmsg_auth_key' => filled($settings['whatsapp_nexmsg_auth_key'] ?? null),
                    'msg91_auth_key' => filled($settings['whatsapp_msg91_auth_key'] ?? null),
                    'msg91_utility_auth_key' => filled($settings['whatsapp_msg91_utility_auth_key'] ?? null),
                    'msg91_marketing_auth_key' => filled($settings['whatsapp_msg91_marketing_auth_key'] ?? null),
                    'meta_access_token' => filled($settings['whatsapp_meta_access_token'] ?? null),
                    'twilio_auth_token' => filled($settings['whatsapp_twilio_auth_token'] ?? null),
                ],
            ],
            SettingSection::Notifications => [
                'order_phone_alerts_enabled' => $settings['order_phone_alerts_enabled'] ?? false,
                'order_phone_alert_numbers' => $settings['order_phone_alert_numbers'] ?? [],
                'order_phone_alert_sources' => $settings['order_phone_alert_sources'] ?? [],
                'order_phone_alert_template_id' => $settings['order_phone_alert_template_id'] ?? '',
                'notifications_enabled' => $settings['notifications_enabled'] ?? true,
                'notifications_whatsapp_enabled' => $settings['notifications_whatsapp_enabled'] ?? false,
                'notifications_in_app_enabled' => $settings['notifications_in_app_enabled'] ?? true,
                'order_notifications_whatsapp_enabled' => $settings['order_notifications_whatsapp_enabled'] ?? true,
                'order_notifications_customer_enabled' => $settings['order_notifications_customer_enabled'] ?? true,
                'order_notifications_waiter_enabled' => $settings['order_notifications_waiter_enabled'] ?? true,
                'order_notifications_other_enabled' => $settings['order_notifications_other_enabled'] ?? true,
                'notifications_email_enabled' => $settings['notifications_email_enabled'] ?? false,
                'notifications_sms_enabled' => $settings['notifications_sms_enabled'] ?? false,
                'notifications_push_enabled' => $settings['notifications_push_enabled'] ?? false,
                'admin_failure_notifications_enabled' => $settings['admin_failure_notifications_enabled'] ?? true,
                'customer_order_created_notification_enabled' => $settings['customer_order_created_notification_enabled'] ?? true,
                'customer_order_confirmed_notification_enabled' => $settings['customer_order_confirmed_notification_enabled'] ?? true,
                'customer_order_preparing_notification_enabled' => $settings['customer_order_preparing_notification_enabled'] ?? true,
                'customer_order_ready_notification_enabled' => $settings['customer_order_ready_notification_enabled'] ?? true,
                'customer_order_cancelled_notification_enabled' => $settings['customer_order_cancelled_notification_enabled'] ?? true,
                'admin_delivery_created_notification_enabled' => $settings['admin_delivery_created_notification_enabled'] ?? true,
                'customer_delivery_assigned_notification_enabled' => $settings['customer_delivery_assigned_notification_enabled'] ?? true,
                'customer_delivery_picked_up_notification_enabled' => $settings['customer_delivery_picked_up_notification_enabled'] ?? true,
                'customer_delivery_on_way_notification_enabled' => $settings['customer_delivery_on_way_notification_enabled'] ?? true,
                'customer_delivery_delivered_notification_enabled' => $settings['customer_delivery_delivered_notification_enabled'] ?? true,
                'admin_delivery_cancelled_notification_enabled' => $settings['admin_delivery_cancelled_notification_enabled'] ?? true,
                'admin_delivery_failure_notification_enabled' => $settings['admin_delivery_failure_notification_enabled'] ?? true,
                'inventory_low_stock_alerts_enabled' => $settings['inventory_low_stock_alerts_enabled'] ?? true,
            ],
            SettingSection::CustomerApp => [
                'customer_app_enabled' => $settings['customer_app_enabled'] ?? true,
                'customer_app_guest_checkout_enabled' => $settings['customer_app_guest_checkout_enabled'] ?? true,
                'customer_app_reservations_enabled' => $settings['customer_app_reservations_enabled'] ?? true,
                'customer_app_delivery_enabled' => $settings['customer_app_delivery_enabled'] ?? true,
                'customer_app_pickup_enabled' => $settings['customer_app_pickup_enabled'] ?? true,
                'customer_app_table_qr_enabled' => $settings['customer_app_table_qr_enabled'] ?? true,
                'customer_app_order_tracking_enabled' => $settings['customer_app_order_tracking_enabled'] ?? true,
            ],
            SettingSection::Delivery => [
                'platform_integration_enabled' => (bool) config('delivery.integration_enabled', false),
                'delivery_ordering_enabled' => $settings['delivery_ordering_enabled'] ?? true,
                'delivery_schedule_enabled' => $settings['delivery_schedule_enabled'] ?? false,
                'delivery_hours' => $settings['delivery_hours'] ?? [],
                'delivery_enabled' => $settings['delivery_enabled'] ?? false,
                'third_party_delivery_enabled' => $settings['third_party_delivery_enabled'] ?? false,
                'automatic_partner_assignment_enabled' => $settings['automatic_partner_assignment_enabled'] ?? false,
                'delivery_tracking_enabled' => $settings['delivery_tracking_enabled'] ?? false,
                'delivery_quotes_enabled' => $settings['delivery_quotes_enabled'] ?? false,
                'auto_fallback_partner_enabled' => $settings['auto_fallback_partner_enabled'] ?? false,
                'delivery_cod_enabled' => $settings['delivery_cod_enabled'] ?? false,
                'delivery_prepaid_enabled' => $settings['delivery_prepaid_enabled'] ?? false,
                'delivery_location_required_at_login' => $settings['delivery_location_required_at_login'] ?? false,
                'mandatory_delivery_location_enabled' => $settings['mandatory_delivery_location_enabled'] ?? false,
                'partner_api_delivery_radius_policy' => $settings['partner_api_delivery_radius_policy'] ?? 'enforce',
                'delivery_selection_strategy' => $settings['delivery_selection_strategy'] ?? 'cheapest',
                'maximum_delivery_radius_km' => $settings['maximum_delivery_radius_km'] ?? null,
                'maximum_provider_delivery_cost' => $settings['maximum_provider_delivery_cost'] ?? null,
                'maximum_delivery_eta_minutes' => $settings['maximum_delivery_eta_minutes'] ?? null,
                'delivery_wallet_gst_rate' => $settings['delivery_wallet_gst_rate'] ?? 18,
                'delivery_platform_fee_per_order' => $settings['delivery_platform_fee_per_order'] ?? 1,
                'delivery_pricing_method' => $settings['delivery_pricing_method'] ?? 'slabs',
                'delivery_charge_slabs' => $settings['delivery_charge_slabs'] ?? [],
                'base_delivery_charge' => $settings['base_delivery_charge'] ?? 0,
                'base_distance_km' => $settings['base_distance_km'] ?? 0,
                'per_additional_km_charge' => $settings['per_additional_km_charge'] ?? 0,
                'delivery_customer_fee_gst_enabled' => $settings['delivery_customer_fee_gst_enabled'] ?? false,
                'delivery_customer_fee_gst_rate' => $settings['delivery_customer_fee_gst_rate'] ?? 18,
                'delivery_free_rules' => $settings['delivery_free_rules'] ?? [],
                'free_delivery_above_order_amount' => $settings['free_delivery_above_order_amount'] ?? null,
                'delivery_provider_codes' => $settings['delivery_provider_codes'] ?? [],
                'delivery_uengage_store_id' => $settings['delivery_uengage_store_id'] ?? null,
                'delivery_map_provider' => $settings['delivery_map_provider'] ?? 'openstreetmap',
                'map_api_key_configured' => filled($settings['delivery_map_api_key'] ?? null),
                'encryptable' => ['delivery_map_api_key' => null],
                'provider_credentials_configured' => filled($settings['delivery_uengage_api_key'] ?? null),
                'provider_store_configured' => filled($settings['delivery_uengage_store_id'] ?? null),
                'provider_environment' => config('delivery.uengage.environment', 'sandbox'),
                'provider_controls' => [
                    'integration_enabled' => (bool) config('delivery.integration_enabled', false),
                    'sandbox_enabled' => (bool) config('delivery.sandbox_enabled', false),
                    'production_enabled' => (bool) config('delivery.uengage.production_enabled', false),
                    'booking_enabled' => (bool) config('delivery.booking_enabled', false),
                    'cancellation_enabled' => (bool) config('delivery.cancellation_enabled', false),
                    'webhook_enabled' => (bool) config('delivery.webhook_enabled', false),
                    'automatic_retry_enabled' => false,
                ],
                'provider_booking_contract_ready' => (bool) config('delivery.booking_enabled', false)
                    && (bool) config('delivery.integration_enabled', false)
                    && ((string) config('delivery.uengage.environment', 'sandbox') !== 'production'
                        || (bool) config('delivery.uengage.production_enabled', false))
                    && filled(app(\Modules\Order\Delivery\PlatformDeliveryCredentials::class)->apiKey())
                    && filled(app(\Modules\Order\Delivery\PlatformDeliveryCredentials::class)->storeId()),
                'provider_webhook_contract_ready' => false,
                'provider_last_health_check_at' => null,
                'provider_readiness' => [
                    'configuration' => filled($settings['delivery_uengage_api_key'] ?? null)
                        && filled($settings['delivery_uengage_store_id'] ?? null) ? 'configured_not_verified' : 'incomplete',
                    'integration' => (bool) config('delivery.integration_enabled', false) ? 'server_enabled' : 'disabled',
                    'environment' => (string) config('delivery.uengage.environment', 'sandbox'),
                    'environment_traffic' => (string) config('delivery.uengage.environment', 'sandbox') === 'production'
                        ? ((bool) config('delivery.uengage.production_enabled', false) ? 'server_enabled' : 'disabled')
                        : ((bool) config('delivery.sandbox_enabled', false) ? 'server_enabled' : 'disabled'),
                    'booking' => 'blocked_provider_contract',
                    'cancellation' => 'blocked_pending_safe_task_and_contract',
                    'webhook' => 'blocked_provider_contract',
                    'production' => 'blocked_release_review',
                ],
            ],
            SettingSection::Firebase => [
                'firebase_enabled' => $settings['firebase_enabled'] ?? filled(config('services.firebase.credentials')),
                'firebase_project_id' => $settings['firebase_project_id'] ?? config('services.firebase.project_id'),
                'firebase_web_api_key' => $settings['firebase_web_api_key'] ?? config('services.firebase.web_api_key'),
                'firebase_auth_domain' => $settings['firebase_auth_domain'] ?? config('services.firebase.auth_domain'),
                'firebase_storage_bucket' => $settings['firebase_storage_bucket'] ?? config('services.firebase.storage_bucket'),
                'firebase_messaging_sender_id' => $settings['firebase_messaging_sender_id'] ?? config('services.firebase.messaging_sender_id'),
                'firebase_app_id' => $settings['firebase_app_id'] ?? config('services.firebase.app_id'),
                'firebase_measurement_id' => $settings['firebase_measurement_id'] ?? config('services.firebase.measurement_id'),
                'firebase_vapid_key' => $settings['firebase_vapid_key'] ?? config('services.firebase.vapid_key'),
                'service_account_configured' => filled($settings['firebase_service_account_json'] ?? null) || filled(config('services.firebase.credentials')),
                'web_push_configured' => filled($settings['firebase_web_api_key'] ?? config('services.firebase.web_api_key'))
                    && filled($settings['firebase_app_id'] ?? config('services.firebase.app_id'))
                    && filled($settings['firebase_messaging_sender_id'] ?? config('services.firebase.messaging_sender_id')),
                'encryptable' => [
                    'firebase_service_account_json' => $settings['firebase_service_account_json'] ?? null,
                ],
            ],
            SettingSection::Analytics => [
                'dashboard_smart_insights_enabled' => $settings['dashboard_smart_insights_enabled'] ?? true,
                'dashboard_smart_insights_window_days' => $settings['dashboard_smart_insights_window_days'] ?? 30,
                'dashboard_smart_insights_limit' => $settings['dashboard_smart_insights_limit'] ?? 5,
                'dashboard_ai_assistant_enabled' => $settings['dashboard_ai_assistant_enabled'] ?? false,
                'pos_risk_notifications_enabled' => $settings['pos_risk_notifications_enabled'] ?? true,
                'dashboard_ai_base_url' => $settings['dashboard_ai_base_url'] ?? config('services.openai.base_uri', 'https://api.openai.com/v1'),
                'dashboard_ai_model' => $settings['dashboard_ai_model'] ?? config('services.openai.model', 'gpt-4o-mini'),
                'dashboard_ai_system_prompt' => $settings['dashboard_ai_system_prompt'] ?? __('dashboard::dashboards.ai.default_system_prompt'),
                'dashboard_ai_temperature' => $settings['dashboard_ai_temperature'] ?? 0.4,
                'dashboard_ai_max_tokens' => $settings['dashboard_ai_max_tokens'] ?? 250,
            ],
            SettingSection::Appearance => [
                'appearance_primary_color' => $settings['appearance_primary_color'] ?? '#F57C00',
                'appearance_secondary_color' => $settings['appearance_secondary_color'] ?? '#043A63',
                'appearance_sidebar_color' => $settings['appearance_sidebar_color'] ?? null,
                'appearance_header_color' => $settings['appearance_header_color'] ?? null,
                'appearance_button_color' => $settings['appearance_button_color'] ?? null,
                'appearance_table_highlight_color' => $settings['appearance_table_highlight_color'] ?? null,
                'appearance_mode' => $settings['appearance_mode'] ?? 'light',
                'appearance_background_type' => $settings['appearance_background_type'] ?? 'default',
                'appearance_panel_background' => $settings['appearance_panel_background'] ?? null,
                'appearance_gradient_background' => $settings['appearance_gradient_background'] ?? null,
                'appearance_system_title' => $settings['appearance_system_title'] ?? ($settings['app_name'] ?? config('app.name')),
                'appearance_admin_title' => $settings['appearance_admin_title'] ?? ($settings['app_name'] ?? config('app.name')),
                'appearance_browser_title' => $settings['appearance_browser_title'] ?? ($settings['app_name'] ?? config('app.name')),
                'appearance_footer_text' => $settings['appearance_footer_text'] ?? null,
                'appearance_print_terms' => $settings['appearance_print_terms'] ?? null,
                'appearance_login_welcome_text' => $settings['appearance_login_welcome_text'] ?? null,
                'appearance_login_background_image' => Media::getCacheMedia($settings['appearance_login_background_image'] ?? null, true),
            ],
            SettingSection::SystemConfiguration => [
                'app_url' => config('app.url'),
                'frontend_url' => env('FRONTEND_URL'),
                'api_url' => env('API_URL'),
                'socket_url' => env('SOCKET_URL'),
                'media_url' => env('MEDIA_URL'),
                'webhook_base_url' => env('WEBHOOK_BASE_URL'),
                'login_redirect_url' => $settings['login_redirect_url'] ?? env('FRONTEND_URL'),
                'logout_redirect_url' => $settings['logout_redirect_url'] ?? env('FRONTEND_URL'),
            ]
        };
    }

    /** {@inheritDoc} */
    public function getMeta(SettingSection $section): array
    {
        return match ($section) {
            SettingSection::General => [
                'countries' => Country::toList(),
                'locales' => Locale::toList(),
                'timezones' => TimeZone::toList(),
                'date_formats' => DateFormats::toList(),
                'time_formats' => TimeFormats::toList(),
                'days' => Day::toArrayTrans(),
            ],
            SettingSection::Currency => [
                'currencies' => Currency::toList(),
                'frequencies' => Frequency::toArrayTrans(),
                'exchange_services' => ExchangeService::toArrayTrans(),
            ],
            SettingSection::Mail => [
                'mailers' => Mailer::toArrayTrans(),
                'encryption_protocols' => MailEncryptionProtocol::toArrayTrans(),
            ],
            SettingSection::Filesystem => [
                'disks' => FilesystemDisk::toArrayTrans(),
            ],
            SettingSection::Kitchen => [
                'modes' => AutoRefreshMode::toArrayTrans(),
            ],
            SettingSection::WhatsApp => [
                'providers' => array_map(
                    fn (WhatsAppProvider $provider) => ['id' => $provider->value, 'label' => $provider->trans()],
                    WhatsAppProvider::cases()
                ),
            ],
            SettingSection::Notifications => [
                'order_phone_alerts_enabled' => $settings['order_phone_alerts_enabled'] ?? false,
                'order_phone_alert_numbers' => $settings['order_phone_alert_numbers'] ?? [],
                'order_phone_alert_sources' => $settings['order_phone_alert_sources'] ?? [],
                'order_phone_alert_template_id' => $settings['order_phone_alert_template_id'] ?? '',
                'channels' => NotificationChannel::toArray(),
            ],
            SettingSection::Appearance => [
                'modes' => [
                    ['id' => 'light', 'name' => __('setting::settings.appearance_modes.light')],
                    ['id' => 'dark', 'name' => __('setting::settings.appearance_modes.dark')],
                ],
                'background_types' => [
                    ['id' => 'default', 'name' => __('setting::settings.background_types.default')],
                    ['id' => 'gradient', 'name' => __('setting::settings.background_types.gradient')],
                    ['id' => 'custom', 'name' => __('setting::settings.background_types.custom')],
                ],
            ],
            SettingSection::SystemConfiguration => [
                'managed_keys' => [
                    'APP_URL',
                    'FRONTEND_URL',
                    'API_URL',
                    'SOCKET_URL',
                    'MEDIA_URL',
                    'WEBHOOK_BASE_URL',
                ],
            ],
            default => []
        };
    }

    /** {@inheritDoc} */
    public function update(SettingSection $section, array $data): void
    {
        // Secret inputs are blank after the settings screen loads. Treat a
        // blank value as "unchanged" so an ordinary toggle/template edit
        // cannot erase a working provider credential. Secret rotation still
        // works by submitting a non-empty replacement.
        if (isset($data['encryptable']) && is_array($data['encryptable'])) {
            $data['encryptable'] = array_filter(
                $data['encryptable'],
                static fn (mixed $value): bool => $value !== null && $value !== '',
            );
        }

        if ($section === SettingSection::WhatsApp
            && array_key_exists('whatsapp_nexmsg_account_id', $data['encryptable'] ?? [])
            && (string) $data['encryptable']['whatsapp_nexmsg_account_id'] !== (string) setting('whatsapp_nexmsg_account_id')
            && filled(setting('whatsapp_nexmsg_auth_key'))
            && blank($data['encryptable']['whatsapp_nexmsg_auth_key'] ?? null)) {
            throw ValidationException::withMessages([
                'encryptable.whatsapp_nexmsg_auth_key' => 'Enter a replacement Auth Key when changing the NexMsg Account ID. The existing key belongs to the saved account.',
            ]);
        }

        if ($section === SettingSection::WhatsApp
            && ($data['whatsapp_provider'] ?? null) === WhatsAppProvider::NexMsg->value
            && filter_var($data['whatsapp_enabled'] ?? false, FILTER_VALIDATE_BOOL)
            && blank($data['encryptable']['whatsapp_nexmsg_auth_key'] ?? null)
            && blank(setting('whatsapp_nexmsg_auth_key'))) {
            throw ValidationException::withMessages([
                'encryptable.whatsapp_nexmsg_auth_key' => 'NexMsg Auth Key is required before WhatsApp messaging can be enabled.',
            ]);
        }

        if ($section == SettingSection::Logo) {
            $oldLogo = setting('logo');
            if ($oldLogo != $data['logo']) {
                $base64 = null;
                $logo = Media::getCacheMedia($data['logo']);
                if ($logo) {
                    $base64 = base64_encode($logo->getContent());
                }
                Storage::disk('local')->put('logo.base64', $base64 ?? '');
                $data['logo_mime_type'] = $logo?->mime_type;
            }
        }

        if ($section == SettingSection::SystemConfiguration) {
            $this->updateEnvironment([
                'APP_URL' => $data['app_url'] ?? null,
                'FRONTEND_URL' => $data['frontend_url'] ?? null,
                'API_URL' => $data['api_url'] ?? null,
                'SOCKET_URL' => $data['socket_url'] ?? null,
                'MEDIA_URL' => $data['media_url'] ?? null,
                'WEBHOOK_BASE_URL' => $data['webhook_base_url'] ?? null,
            ]);
            Artisan::call('config:clear');
            Artisan::call('cache:clear');
        }
        if ($section == SettingSection::Delivery && array_key_exists('delivery_cod_enabled', $data)) {
            // Cash on delivery is one decision: keep the checkout option in
            // step with the delivery setting shown on the Delivery page.
            $data['customer_payment_cod_enabled'] = (bool) $data['delivery_cod_enabled'];
        }
        setting($data);
    }

    private function updateEnvironment(array $values): void
    {
        $path = base_path('.env');

        if (! File::exists($path)) {
            return;
        }

        File::ensureDirectoryExists(storage_path('app/env-backups'));
        File::copy($path, storage_path('app/env-backups/.env.'.now()->format('YmdHis').'.bak'));

        $content = File::get($path);

        foreach ($values as $key => $value) {
            if (blank($value)) {
                continue;
            }

            $escapedValue = str_contains($value, ' ') ? '"'.str_replace('"', '\"', $value).'"' : $value;
            $line = "{$key}={$escapedValue}";

            if (preg_match("/^{$key}=.*$/m", $content)) {
                $content = preg_replace("/^{$key}=.*$/m", $line, $content);
            } else {
                $content .= PHP_EOL.$line;
            }
        }

        File::put($path, $content);
    }
}
