<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Support\Eloquent\Model;

class CustomerAppSetting extends Model
{
    protected $fillable = [
        'tenant_id', 'app_enabled', 'sliders_enabled', 'offers_enabled',
        'events_enabled', 'customer_registration_enabled', 'guest_checkout_enabled',
        'delivery_enabled', 'pickup_enabled', 'dine_in_enabled', 'table_qr_enabled',
        'order_tracking_enabled', 'notifications_enabled', 'contact_phone',
        'whatsapp_number', 'address_line', 'map_url', 'social_links', 'settings',
        'published_at', 'content_updated_at',
    ];

    public static function defaults(): array
    {
        return [
            'app_enabled' => true,
            'sliders_enabled' => true,
            'offers_enabled' => true,
            'events_enabled' => true,
            'customer_registration_enabled' => true,
            'guest_checkout_enabled' => true,
            'delivery_enabled' => false,
            'pickup_enabled' => true,
            'dine_in_enabled' => true,
            'table_qr_enabled' => true,
            'order_tracking_enabled' => true,
            'notifications_enabled' => true,
            'contact_phone' => null,
            'whatsapp_number' => null,
            'address_line' => null,
            'map_url' => null,
            'social_links' => [],
            'settings' => [],
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected function casts(): array
    {
        return [
            'app_enabled' => 'boolean',
            'sliders_enabled' => 'boolean',
            'offers_enabled' => 'boolean',
            'events_enabled' => 'boolean',
            'customer_registration_enabled' => 'boolean',
            'guest_checkout_enabled' => 'boolean',
            'delivery_enabled' => 'boolean',
            'pickup_enabled' => 'boolean',
            'dine_in_enabled' => 'boolean',
            'table_qr_enabled' => 'boolean',
            'order_tracking_enabled' => 'boolean',
            'notifications_enabled' => 'boolean',
            'social_links' => 'array',
            'settings' => 'array',
            'published_at' => 'datetime',
            'content_updated_at' => 'datetime',
        ];
    }
}
