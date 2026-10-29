<?php

use Modules\User\Enums\PermissionAction as Action;

return [
    'name' => 'Flight',
    'permissions' => [
        'flight_airports' => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        'flight_airlines' => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        'flight_aircraft' => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        'flight_routes' => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        'flight_schedules' => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        'flight_fares' => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        'flight_fare_families' => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        'flight_inventories' => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        'flight_bookings' => [Action::Index, Action::Show, Action::Create, Action::Cancel, Action::Refund],
        'flight_wallets' => [Action::Index, Action::Show, Action::Edit],
        'flight_markups' => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        'flight_holds' => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        'flight_settlements' => [Action::Index, Action::Show, Action::Create, Action::Edit],
    ],

    // Phase 12: Price Check, Inventory Hold & Locking Configuration
    'hold_ttl'  => env('FLIGHT_HOLD_TTL', 600),   // 10 minutes temporary seat hold
    'lock_ttl'  => env('FLIGHT_LOCK_TTL', 10),    // 10 seconds Redis distributed lock timeout
    'quote_ttl' => env('FLIGHT_QUOTE_TTL', 900),  // 15 minutes immutable quote validity

    // Customer mobile OTP login. The code is delivered through the WhatsApp /
    // email provider of the tenant that owns the customer's latest booking.
    // A number with no bookings falls back to this tenant (leave empty to only
    // allow login for numbers that have booked before).
    'customer_otp_tenant_id' => env('FLIGHT_CUSTOMER_OTP_TENANT_ID'),

    // Airports, airlines, aircraft and cabin classes are private per tenant.
    // false: a new tenant starts with an empty list and adds its own.
    // true:  a new tenant starts with a copy of the platform starter template.
    'copy_starter_catalog' => env('FLIGHT_COPY_STARTER_CATALOG', false),

    // Branding for customer booking/ticket emails. The platform-wide app_name
    // setting belongs to the SaaS console, so flight emails carry their own.
    'mail_brand' => [
        'name' => env('FLIGHT_BRAND_NAME', 'CRT Travels'),
        'color' => env('FLIGHT_BRAND_COLOR', '#F57C00'),
        // Public https URL of the logo; empty uses /images/crt-logo.png on the website.
        'logo_url' => env('FLIGHT_BRAND_LOGO_URL'),
        // Customer website, used for the "View my booking" link and the logo.
        'website_url' => env('FLIGHT_WEBSITE_URL', env('FRONTEND_URL', 'http://localhost:3101')),
        'support_email' => env('FLIGHT_SUPPORT_EMAIL'),
        'support_phone' => env('FLIGHT_SUPPORT_PHONE'),
    ],
];
