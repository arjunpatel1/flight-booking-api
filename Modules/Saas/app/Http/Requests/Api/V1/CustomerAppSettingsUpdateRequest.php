<?php

namespace Modules\Saas\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CustomerAppSettingsUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'app_enabled' => ['nullable', 'boolean'],
            'sliders_enabled' => ['nullable', 'boolean'],
            'offers_enabled' => ['nullable', 'boolean'],
            'events_enabled' => ['nullable', 'boolean'],
            'customer_registration_enabled' => ['nullable', 'boolean'],
            'guest_checkout_enabled' => ['nullable', 'boolean'],
            'delivery_enabled' => ['nullable', 'boolean'],
            'pickup_enabled' => ['nullable', 'boolean'],
            'dine_in_enabled' => ['nullable', 'boolean'],
            'table_qr_enabled' => ['nullable', 'boolean'],
            'order_tracking_enabled' => ['nullable', 'boolean'],
            'notifications_enabled' => ['nullable', 'boolean'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'whatsapp_number' => ['nullable', 'string', 'max:40'],
            'address_line' => ['nullable', 'string', 'max:500'],
            'map_url' => ['nullable', 'url', 'max:2048'],
            'social_links' => ['nullable', 'array', 'max:50'],
            'settings' => ['nullable', 'array', 'max:50'],
            'settings.otp_channels' => ['nullable', 'array', 'min:1', 'max:2'],
            'settings.otp_channels.*' => ['required', 'string', 'distinct', 'in:whatsapp,email'],
            'settings.email_notifications_enabled' => ['nullable', 'boolean'],
            'settings.email_templates' => ['nullable', 'array', 'max:25'],
            'settings.email_templates.*' => ['array'],
            'settings.email_templates.*.id' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/'],
            'settings.email_templates.*.name' => ['required', 'string', 'max:120'],
            'settings.email_templates.*.subject' => ['required', 'string', 'max:180'],
            'settings.email_templates.*.body' => ['required', 'string', 'max:4000'],
            'settings.email_templates.*.is_active' => ['required', 'boolean'],
            'settings.nearby_radius_km' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'settings.google_sign_in_enabled' => ['nullable', 'boolean'],
            'settings.google_server_client_id' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$/'],
            'settings.google_android_client_id' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$/'],
            'settings.apple_sign_in_enabled' => ['nullable', 'boolean'],
            'settings.apple_service_id' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            // Legacy per-branch discovery overrides. Branch columns are now the
            // source of truth; these remain as a fallback for existing tenants.
            'settings.delivery_eta_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'settings.branch_cuisines' => ['nullable', 'array', 'max:100'],
            'settings.branch_cuisines.*' => ['nullable', 'array', 'max:12'],
            'settings.branch_cuisines.*.*' => ['required', 'string', 'max:60'],
            'settings.branch_offers' => ['nullable', 'array', 'max:100'],
            'settings.branch_offers.*' => ['nullable', 'string', 'max:120'],
            'locations' => ['nullable', 'array', 'max:100'],
            'locations.*.branch_id' => ['required', 'integer'],
            'locations.*.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'locations.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'locations.*.delivery_radius_km' => ['nullable', 'numeric', 'min:0.1', 'max:500'],
            'locations.*.delivery_minimum_order' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function payload(): array
    {
        return $this->validated();
    }
}
