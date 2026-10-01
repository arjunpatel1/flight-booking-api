<?php

namespace Modules\Setting\Http\Requests\Api\V1;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Modules\Core\Http\Requests\Request;
use Modules\Currency\Currency;
use Modules\Currency\Enums\ExchangeService;
use Modules\Notification\Enums\WhatsAppProvider;
use Modules\Setting\Enums\AutoRefreshMode;
use Modules\Setting\Enums\SettingSection;
use Modules\Support\Country;
use Modules\Support\DateFormats;
use Modules\Support\Enums\Day;
use Modules\Support\Enums\FilesystemDisk;
use Modules\Support\Enums\Frequency;
use Modules\Support\Enums\MailEncryptionProtocol;
use Modules\Support\Enums\Mailer;
use Modules\Support\Locale;
use Modules\Support\TimeFormats;
use Modules\Support\TimeZone;

/**
 * @property SettingSection $section
 */
class SaveSettingRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $section = $this->section instanceof SettingSection
            ? $this->section
            : match (true) {
                str_contains($this->path(), 'settings/appearance') => SettingSection::Appearance,
                str_contains($this->path(), 'settings/system_configuration') => SettingSection::SystemConfiguration,
                str_contains($this->path(), 'settings/delivery') => SettingSection::Delivery,
                default => $this->section,
            };

        $rules = match ($section) {
            SettingSection::General => [
                'supported_countries' => 'required|array',
                'supported_countries.*' => ['required', Rule::in(Country::codes())],
                'default_country' => 'required|in_array:supported_countries.*',
                'supported_locales' => 'required|array',
                'supported_locales.*' => ['required', Rule::in(Locale::codes())],
                'default_locale' => 'required|in_array:supported_locales.*',
                'default_timezone' => ['required', Rule::in(TimeZone::keys())],
                'default_date_format' => ['required', Rule::in(DateFormats::keys())],
                'default_time_format' => ['required', Rule::in(TimeFormats::keys())],
                'start_of_week' => ['required', Rule::enum(Day::class)],
                'end_of_week' => ['required', Rule::enum(Day::class)],
            ],
            SettingSection::Application => [
                ...$this->getTranslationRules(['app_name' => 'required|min:1|max:200']),
            ],
            SettingSection::Currency => [
                'supported_currencies' => 'required|array',
                'supported_currencies.*' => ['required', Rule::in(Currency::codes())],
                'default_currency' => 'required|in_array:supported_currencies.*',
                'currency_rate_exchange_service' => ['nullable', Rule::enum(ExchangeService::class)],
                'fixer_access_key' => 'required_if:currency_rate_exchange_service,fixer',
                'forge_api_key' => 'required_if:currency_rate_exchange_service,forge',
                'currency_data_feed_api_key' => 'required_if:currency_rate_exchange_service,currency_data_feed',
                'auto_refresh_currency_rates' => 'required|boolean',
                'auto_refresh_currency_rate_frequency' => ['required_if:auto_refresh_currency_rates,1', 'nullable', Rule::enum(Frequency::class)],
            ],
            SettingSection::Mail => [
                'mail_mailer' => ['required', Rule::enum(Mailer::class)],
                'mail_from_address' => 'required|email',
                'mail_from_name' => 'required|string|min:1|max:200',
                'mail_host' => 'required_if:mail_mailer,smtp|string',
                'mail_port' => 'required_if:mail_mailer,smtp|integer|between:1,65535',
                'mail_username' => 'nullable|string',
                'mail_password' => 'nullable|string',
                'mail_encryption' => ['required_if:mail_mailer,smtp', Rule::enum(MailEncryptionProtocol::class)],
            ],
            SettingSection::Filesystem => [
                'default_filesystem_disk' => ['required', Rule::enum(FilesystemDisk::class)],
                'private_filesystem_disk' => ['nullable', Rule::enum(FilesystemDisk::class)],
                'filesystem_s3_use_path_style_endpoint' => 'required_if:default_filesystem_disk,s3|nullable|boolean',
                'filesystem_s3_url' => 'required_if:default_filesystem_disk,s3|nullable|string|url',
                'filesystem_s3_endpoint' => 'required_if:default_filesystem_disk,s3|nullable|string',
                'filesystem_s3_region' => 'required_if:default_filesystem_disk,s3|nullable|string',
                'filesystem_s3_bucket' => 'required_if:default_filesystem_disk,s3|nullable|string',
                'encryptable.filesystem_s3_key' => 'required_if:default_filesystem_disk,s3|nullable|string',
                'encryptable.filesystem_s3_secret' => 'required_if:default_filesystem_disk,s3|nullable|string',
            ],
            SettingSection::Logo => [
                'logo' => ['bail', 'nullable', 'integer', $this->tenantImageRule()],
                'invoice_logo' => ['bail', 'nullable', 'integer', $this->tenantImageRule()],
                'favicon' => ['bail', 'required', 'integer', $this->tenantImageRule()],
                'loader_logo' => ['bail', 'nullable', 'integer', $this->tenantImageRule()],
            ],
            SettingSection::Kitchen => [
                'auto_refresh_enabled' => 'required|boolean',
                'auto_refresh_mode' => ['required_if:auto_refresh_enabled,true', 'nullable', Rule::enum(AutoRefreshMode::class)],
                'auto_refresh_interval' => 'required_if:auto_refresh_mode,smart_polling|nullable|integer|min:1000|max:600000',
                'auto_refresh_pause_on_idle' => 'required_if:auto_refresh_mode,smart_polling|boolean',
                'auto_refresh_idle_timeout' => 'required_if:auto_refresh_pause_on_idle,true|nullable|integer|min:10|max:3600',
                'kitchen_sound_alert_enabled' => 'required|boolean',
                'kitchen_print_with_payment_enabled' => 'sometimes|boolean',
                'customer_order_auto_print_mode' => ['required', Rule::in(['disabled', 'kot', 'kot_invoice', 'invoice'])],
                'waiter_table_status_flow_enabled' => 'required|boolean',
                'kitchen_delayed_order_minutes' => 'required|integer|min:1|max:240',
            ],
            SettingSection::WhatsApp => [
                'whatsapp_enabled' => 'required|boolean',
                'whatsapp_provider' => ['required', Rule::enum(WhatsAppProvider::class)],
                'whatsapp_delivery_alerts_enabled' => 'required|boolean',
                'whatsapp_marketing_campaigns_enabled' => 'required|boolean',
                'crm_inactive_customer_days' => 'required|integer|min:1|max:365',
                'crm_recent_customer_days' => 'required|integer|min:1|max:365',
                'crm_high_value_customer_min_spend' => 'required|numeric|min:0',
                'crm_inactive_customer_automation_enabled' => 'required|boolean',
                'crm_inactive_customer_automation_time' => 'required|date_format:H:i',
                'crm_inactive_customer_coupon_code' => 'nullable|string|max:80',
                'crm_inactive_customer_offer_title' => 'nullable|string|max:120',
                'crm_inactive_customer_offer_valid_until' => 'nullable|date',
                'crm_birthday_offer_automation_enabled' => 'required|boolean',
                'crm_birthday_offer_automation_time' => 'required|date_format:H:i',
                'crm_birthday_offer_coupon_code' => 'nullable|string|max:80',
                'crm_birthday_offer_title' => 'nullable|string|max:120',
                'crm_anniversary_offer_automation_enabled' => 'required|boolean',
                'crm_anniversary_offer_automation_time' => 'required|date_format:H:i',
                'crm_anniversary_offer_coupon_code' => 'nullable|string|max:80',
                'crm_anniversary_offer_title' => 'nullable|string|max:120',
                'whatsapp_templates' => 'nullable|array',
                'whatsapp_templates.*.id' => 'required|string|max:120|distinct',
                'whatsapp_templates.*.template_id' => ['sometimes', 'required', 'string', 'max:191', 'regex:/^[A-Za-z0-9._-]+$/', 'distinct'],
                'whatsapp_templates.*.name' => 'required|string|max:120',
                'whatsapp_templates.*.description' => 'nullable|string|max:500',
                'whatsapp_templates.*.message' => 'nullable|string|max:2000',
                'whatsapp_templates.*.category' => 'nullable|string|max:60',
                'whatsapp_templates.*.event' => 'nullable|string|max:120',
                'whatsapp_templates.*.is_active' => 'required|boolean',
                'whatsapp_templates.*.namespace' => 'nullable|string|max:160',
                'whatsapp_templates.*.language_code' => 'nullable|string|max:10',
                'whatsapp_templates.*.variables' => 'nullable|array',
                'whatsapp_templates.*.variables.*' => 'string|max:80',
                'whatsapp_templates.*.component_keys' => 'nullable|array',
                'whatsapp_templates.*.component_keys.*' => 'string|max:80',
                'whatsapp_templates.*.buttons' => 'nullable|array|max:3',
                'whatsapp_templates.*.buttons.*.type' => 'required|string|in:url,quick_reply',
                'whatsapp_templates.*.buttons.*.index' => 'required|integer|min:0|max:2',
                'whatsapp_templates.*.buttons.*.label' => 'required|string|max:25',
                'whatsapp_templates.*.buttons.*.variable' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_]+$/'],
                'whatsapp_templates.*.provider' => 'nullable|string|in:nexmsg,msg91,meta,twilio',
                'whatsapp_templates.*.approval_status' => 'nullable|string|max:40',
                'whatsapp_templates.*.provider_approval_status' => 'nullable|string|max:40',
                'whatsapp_templates.*.provider_template_id' => 'nullable|string|max:191',
                'whatsapp_templates.*.provider_rejection_reason' => 'nullable|string|max:1000',
                'whatsapp_templates.*.provider_synced_at' => 'nullable|date',
                'whatsapp_templates.*.local_changed_at' => 'nullable|date',
                'whatsapp_msg91_marketing_reuse_utility' => 'required|boolean',
                'whatsapp_meta_graph_version' => ['required', 'string', "regex:/^\d+\.\d+$/"],
                'encryptable.whatsapp_nexmsg_account_id' => 'required_if:whatsapp_provider,nexmsg|nullable|string|max:191',
                'encryptable.whatsapp_nexmsg_auth_key' => 'nullable|string|max:4096',
                'encryptable.whatsapp_msg91_auth_key' => 'nullable|string',
                'encryptable.whatsapp_msg91_api_url' => 'nullable|url|max:255',
                'encryptable.whatsapp_msg91_integrated_number' => 'nullable|string|max:30',
                'encryptable.whatsapp_msg91_sender_id' => 'nullable|string',
                'encryptable.whatsapp_msg91_webhook_secret' => 'nullable|string',
                'encryptable.whatsapp_msg91_utility_auth_key' => 'nullable|string',
                'encryptable.whatsapp_msg91_utility_integrated_number' => 'nullable|string|max:30',
                'encryptable.whatsapp_msg91_utility_webhook_secret' => 'nullable|string',
                'encryptable.whatsapp_msg91_marketing_auth_key' => 'nullable|string',
                'encryptable.whatsapp_msg91_marketing_integrated_number' => 'nullable|string|max:30',
                'encryptable.whatsapp_msg91_marketing_webhook_secret' => 'nullable|string',
                'encryptable.whatsapp_meta_access_token' => 'nullable|string',
                'encryptable.whatsapp_meta_phone_number_id' => 'nullable|string',
                'encryptable.whatsapp_meta_business_account_id' => 'nullable|string|max:80',
                'encryptable.whatsapp_meta_webhook_secret' => 'nullable|string',
                'encryptable.whatsapp_twilio_sid' => 'nullable|string',
                'encryptable.whatsapp_twilio_auth_token' => 'nullable|string',
                'encryptable.whatsapp_twilio_from' => 'nullable|string',
            ],
            SettingSection::Notifications => [
                'order_phone_alerts_enabled' => 'sometimes|boolean',
                'order_phone_alert_numbers' => 'required_if:order_phone_alerts_enabled,true|array|max:10',
                'order_phone_alert_numbers.*' => ['required', 'string', 'distinct', 'regex:/^\+[1-9][0-9]{7,14}$/'],
                'order_phone_alert_sources' => 'required_if:order_phone_alerts_enabled,true|array|max:10',
                'order_phone_alert_sources.*' => 'required|string|distinct|in:whatsapp,customer_app,customer_web,qr,waiter_app,partner,aggregator,pos,admin,portal',
                'order_phone_alert_template_id' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
                'notifications_enabled' => 'required|boolean',
                'notifications_whatsapp_enabled' => 'required|boolean',
                'notifications_in_app_enabled' => 'required|boolean',
                'order_notifications_whatsapp_enabled' => 'required|boolean',
                'order_notifications_customer_enabled' => 'required|boolean',
                'order_notifications_waiter_enabled' => 'required|boolean',
                'order_notifications_other_enabled' => 'required|boolean',
                'notifications_email_enabled' => 'required|boolean',
                'notifications_sms_enabled' => 'required|boolean',
                'notifications_push_enabled' => 'required|boolean',
                'admin_failure_notifications_enabled' => 'required|boolean',
                'customer_order_created_notification_enabled' => 'sometimes|boolean',
                'customer_order_confirmed_notification_enabled' => 'sometimes|boolean',
                'customer_order_preparing_notification_enabled' => 'sometimes|boolean',
                'customer_order_ready_notification_enabled' => 'sometimes|boolean',
                'customer_order_cancelled_notification_enabled' => 'sometimes|boolean',
                'admin_delivery_created_notification_enabled' => 'sometimes|boolean',
                'customer_delivery_assigned_notification_enabled' => 'sometimes|boolean',
                'customer_delivery_picked_up_notification_enabled' => 'sometimes|boolean',
                'customer_delivery_on_way_notification_enabled' => 'sometimes|boolean',
                'customer_delivery_delivered_notification_enabled' => 'sometimes|boolean',
                'admin_delivery_cancelled_notification_enabled' => 'sometimes|boolean',
                'admin_delivery_failure_notification_enabled' => 'sometimes|boolean',
                'inventory_low_stock_alerts_enabled' => 'required|boolean',
            ],
            SettingSection::CustomerApp => [
                'customer_app_enabled' => 'required|boolean',
                'customer_app_guest_checkout_enabled' => 'required|boolean',
                'customer_app_reservations_enabled' => 'required|boolean',
                'customer_app_delivery_enabled' => 'required|boolean',
                'customer_app_pickup_enabled' => 'required|boolean',
                'customer_app_table_qr_enabled' => 'required|boolean',
                'customer_app_order_tracking_enabled' => 'required|boolean',
            ],
            SettingSection::Delivery => [
                'delivery_ordering_enabled' => 'sometimes|boolean',
                'delivery_schedule_enabled' => 'sometimes|boolean',
                'delivery_hours' => 'required_if:delivery_schedule_enabled,true|array|max:7',
                'delivery_enabled' => 'required|boolean',
                'third_party_delivery_enabled' => 'required|boolean',
                'automatic_partner_assignment_enabled' => 'required|boolean',
                'delivery_tracking_enabled' => 'required|boolean',
                'delivery_quotes_enabled' => 'required|boolean',
                'auto_fallback_partner_enabled' => 'required|boolean',
                'delivery_cod_enabled' => 'required|boolean',
                'delivery_prepaid_enabled' => 'required|boolean',
                'delivery_location_required_at_login' => 'required|boolean',
                'mandatory_delivery_location_enabled' => 'sometimes|boolean',
                'partner_api_delivery_radius_policy' => 'sometimes|in:enforce,bypass',
                'delivery_selection_strategy' => 'required|in:cheapest,fastest,balanced,priority,manual',
                'maximum_delivery_radius_km' => 'nullable|numeric|between:0.1,500',
                'maximum_provider_delivery_cost' => 'nullable|numeric|min:0',
                'maximum_delivery_eta_minutes' => 'nullable|integer|between:1,240',
                'delivery_wallet_gst_rate' => 'sometimes|numeric|between:0,100',
                'delivery_platform_fee_per_order' => 'sometimes|numeric|min:0|max:10000',
                'delivery_pricing_method' => 'required|in:slabs,base_plus_km',
                'delivery_charge_slabs' => 'required|array|max:30',
                'delivery_charge_slabs.*.min_km' => 'required|numeric|min:0',
                'delivery_charge_slabs.*.max_km' => 'required|numeric|gt:delivery_charge_slabs.*.min_km',
                'delivery_charge_slabs.*.charge' => 'required|numeric|min:0',
                'base_delivery_charge' => 'required|numeric|min:0',
                'base_distance_km' => 'required|numeric|min:0',
                'per_additional_km_charge' => 'required|numeric|min:0',
                'delivery_customer_fee_gst_enabled' => 'sometimes|boolean',
                'delivery_customer_fee_gst_rate' => 'sometimes|numeric|between:0,100',
                'delivery_free_rules' => 'sometimes|array|max:20',
                'delivery_free_rules.*.name' => 'required|string|max:120',
                'delivery_free_rules.*.minimum_order_amount' => 'required|numeric|min:0',
                'delivery_free_rules.*.maximum_distance_km' => 'required|numeric|min:0.1|max:500',
                'delivery_free_rules.*.active' => 'required|boolean',
                'free_delivery_above_order_amount' => 'nullable|numeric|min:0',
                'delivery_provider_codes' => 'present|array|max:30',
                'delivery_provider_codes.*' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_-]+$/i', 'distinct'],
                'delivery_uengage_store_id' => 'nullable|string|max:120',
                'encryptable.delivery_uengage_api_key' => 'nullable|string|max:4096',
                'delivery_map_provider' => 'sometimes|in:openstreetmap,google',
                'encryptable.delivery_map_api_key' => 'nullable|string|max:4096',
            ],
            SettingSection::Firebase => [
                'firebase_enabled' => 'required|boolean',
                'firebase_project_id' => 'required_if:firebase_enabled,true|nullable|string|max:120',
                'firebase_web_api_key' => 'nullable|string|max:255',
                'firebase_auth_domain' => 'nullable|string|max:255',
                'firebase_storage_bucket' => 'nullable|string|max:255',
                'firebase_messaging_sender_id' => 'nullable|string|max:80',
                'firebase_app_id' => 'nullable|string|max:120',
                'firebase_measurement_id' => 'nullable|string|max:80',
                'firebase_vapid_key' => 'nullable|string|max:255',
                'encryptable.firebase_service_account_json' => 'nullable|string|max:30000',
            ],
            SettingSection::Analytics => [
                'dashboard_smart_insights_enabled' => 'required|boolean',
                'dashboard_smart_insights_window_days' => 'required|integer|min:7|max:365',
                'dashboard_smart_insights_limit' => 'required|integer|min:3|max:20',
                'dashboard_ai_assistant_enabled' => 'required|boolean',
                'pos_risk_notifications_enabled' => 'required|boolean',
                'dashboard_ai_base_url' => 'nullable|url|max:255',
                'dashboard_ai_model' => 'required|string|max:100',
                'dashboard_ai_system_prompt' => 'required|string|max:1000',
                'dashboard_ai_temperature' => 'required|numeric|min:0|max:2',
                'dashboard_ai_max_tokens' => 'required|integer|min:50|max:1000',
            ],
            SettingSection::Appearance => [
                'appearance_primary_color' => 'required|string|max:20',
                'appearance_secondary_color' => 'required|string|max:20',
                'appearance_sidebar_color' => 'nullable|string|max:20',
                'appearance_header_color' => 'nullable|string|max:20',
                'appearance_button_color' => 'nullable|string|max:20',
                'appearance_table_highlight_color' => 'nullable|string|max:20',
                'appearance_mode' => 'required|in:light,dark',
                'appearance_background_type' => 'required|in:default,gradient,custom',
                'appearance_panel_background' => 'nullable|string|max:20',
                'appearance_gradient_background' => 'nullable|string|max:255',
                'appearance_system_title' => 'required|string|min:1|max:200',
                'appearance_admin_title' => 'required|string|min:1|max:200',
                'appearance_browser_title' => 'required|string|min:1|max:200',
                'appearance_footer_text' => 'nullable|string|max:255',
                'appearance_print_terms' => 'nullable|string|max:500',
                'appearance_login_welcome_text' => 'nullable|string|max:255',
                'appearance_login_background_image' => 'nullable|integer|exists:media,id',
            ],
            SettingSection::SystemConfiguration => [
                'app_url' => 'required|url|max:255',
                'frontend_url' => 'nullable|url|max:255',
                'api_url' => 'nullable|url|max:255',
                'socket_url' => 'nullable|url|max:255',
                'media_url' => 'nullable|url|max:255',
                'webhook_base_url' => 'nullable|url|max:255',
                'login_redirect_url' => 'nullable|url|max:255',
                'logout_redirect_url' => 'nullable|url|max:255',
            ]
        };
        if ($section === SettingSection::Delivery && ! \Modules\Setting\Services\Setting\DeliverySettingAccess::platformAdministrator()) {
            foreach ($rules as $key => $rule) {
                $root = explode('.', $key)[0];
                if (! in_array($root, \Modules\Setting\Services\Setting\DeliverySettingAccess::TENANT_KEYS, true)) {
                    $rules[$key] = 'prohibited';
                }
            }
            $rules['encryptable.delivery_uengage_api_key'] = 'prohibited';
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        if (! str_contains($this->path(), 'settings/delivery')) {
            return;
        }

        $validator->after(function ($validator): void {
            $hours = $this->input('delivery_hours', []);
            $days = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
            if (! is_array($hours) || array_diff(array_keys($hours), $days) !== []) {
                $validator->errors()->add('delivery_hours', 'Use only the seven weekday keys for delivery hours.');
            } else {
                $openDays = 0;
                foreach ($hours as $day => $slot) {
                    if ($slot === []) {
                        continue;
                    }
                    if (! is_array($slot) || array_diff(array_keys($slot), ['open', 'close']) !== []
                        || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', (string) ($slot['open'] ?? ''))
                        || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', (string) ($slot['close'] ?? ''))
                        || $slot['open'] === $slot['close']) {
                        $validator->errors()->add("delivery_hours.{$day}", 'Set valid opening and closing times, or leave this day closed.');
                    } else {
                        $openDays++;
                    }
                }
                if ($this->boolean('delivery_schedule_enabled') && $openDays === 0) {
                    $validator->errors()->add('delivery_hours', 'Select at least one delivery day before enabling the schedule.');
                }
            }
            if ($this->boolean('delivery_location_required_at_login')) {
                $validator->errors()->add('delivery_location_required_at_login', 'Mandatory login location is not available until web and mobile authentication both enforce it.');
            }
            if ($this->boolean('automatic_partner_assignment_enabled')) {
                $platformAdministrator = \Modules\Setting\Services\Setting\DeliverySettingAccess::platformAdministrator();
                $serverReady = false;
                if ($platformAdministrator) {
                    $credentials = app(\Modules\Order\Delivery\PlatformDeliveryCredentials::class);
                    $serverReady = (bool) config('delivery.integration_enabled', false)
                        && (bool) config('delivery.booking_enabled', false)
                        && ((string) config('delivery.uengage.environment', 'sandbox') !== 'production'
                            || (bool) config('delivery.uengage.production_enabled', false))
                        && filled($credentials->apiKey()) && filled($credentials->storeId());
                }
                if (! $serverReady) {
                    $validator->errors()->add('automatic_partner_assignment_enabled', $platformAdministrator
                        ? 'Complete and verify the platform delivery provider configuration before enabling automatic assignment.'
                        : 'Automatic delivery assignment is controlled by NexDine.');
                }
            }
            if ($this->boolean('auto_fallback_partner_enabled')) {
                $validator->errors()->add('auto_fallback_partner_enabled', 'Automatic fallback is blocked because an earlier provider may have created a task without returning a definitive response.');
            }
            if ($validator->errors()->isNotEmpty() || ! $this->boolean('delivery_enabled')
                || $this->input('delivery_pricing_method') !== 'slabs') {
                return;
            }

            $slabs = collect($this->input('delivery_charge_slabs', []))
                ->sortBy(fn (array $slab) => (float) $slab['min_km'])
                ->values();
            if ($slabs->isEmpty() || (float) $slabs->first()['min_km'] !== 0.0) {
                $validator->errors()->add('delivery_charge_slabs', 'Distance slabs must start at 0 km.');

                return;
            }

            foreach ($slabs->skip(1) as $index => $slab) {
                if (abs((float) $slab['min_km'] - (float) $slabs[$index - 1]['max_km']) > 0.000001) {
                    $validator->errors()->add('delivery_charge_slabs', 'Distance slabs must be continuous and cannot overlap.');

                    return;
                }
            }

            $radius = $this->input('maximum_delivery_radius_km');
            if ($radius !== null && abs((float) $slabs->last()['max_km'] - (float) $radius) > 0.000001) {
                $validator->errors()->add('delivery_charge_slabs', 'Distance slabs must end at the maximum delivery radius.');
            }
        });
    }

    private function tenantImageRule(): Exists
    {
        $actor = $this->user();
        $rule = Rule::exists('media', 'id')
            ->where('type', 'file')
            ->where(fn ($query) => $query->where('mime_type', 'like', 'image/%'));

        $isPlatformAdmin = $actor?->isSuperAdmin()
            && ! $actor->assignedToTenant()
            && ! $actor->assignedToBranch();

        if ($actor?->assignedToTenant() && ! $isPlatformAdmin) {
            $creatorIds = DB::table('users')
                ->select('id')
                ->where('tenant_id', $actor->tenantId());

            $rule->where(fn ($query) => $query->whereIn('created_by', $creatorIds));
        }

        return $rule;
    }

    /** {@inheritDoc} */
    protected function availableAttributes(): string
    {
        return 'setting::attributes.settings';
    }
}
