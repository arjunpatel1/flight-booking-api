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
];
