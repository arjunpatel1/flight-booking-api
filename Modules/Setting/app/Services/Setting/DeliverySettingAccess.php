<?php
namespace Modules\Setting\Services\Setting;
final class DeliverySettingAccess
{
    public const TENANT_KEYS = [
        'delivery_ordering_enabled', 'delivery_schedule_enabled', 'delivery_hours',
        'delivery_enabled', 'third_party_delivery_enabled', 'delivery_cod_enabled', 'delivery_prepaid_enabled',
        'mandatory_delivery_location_enabled', 'partner_api_delivery_radius_policy', 'maximum_delivery_radius_km', 'delivery_pricing_method',
        'delivery_charge_slabs', 'base_delivery_charge', 'base_distance_km', 'per_additional_km_charge',
        'delivery_customer_fee_gst_enabled', 'delivery_customer_fee_gst_rate', 'delivery_free_rules', 'free_delivery_above_order_amount',
    ];
    public static function platformAdministrator(): bool
    {
        if (! app()->bound('auth')) return false;
        $user = auth()->user();
        $attributes = $user?->getAttributes() ?? [];
        return $user && array_key_exists('tenant_id', $attributes) && array_key_exists('branch_id', $attributes)
            && $attributes['tenant_id'] === null
            && ($user->getAttributes()['branch_id'] ?? null) === null && $user->hasRole('super_admin')
            && ! app(\Modules\Saas\Support\TenantContext::class)->hasTenant();
    }
}
