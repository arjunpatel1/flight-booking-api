<?php

use Modules\User\Enums\{PermissionAction as Action};

return [
    /*
     * Fleet control plane for POS terminal devices. Delivered to each terminal
     * on its heartbeat so the fleet can be governed centrally.
     */
    'fleet' => [
        // Terminals reporting an app_version below this are told to upgrade.
        // Null disables the forced-upgrade directive.
        'min_app_version' => env('POS_MIN_APP_VERSION'),
        // Window after which a terminal with no heartbeat is considered offline.
        'offline_after_seconds' => (int) env('POS_TERMINAL_OFFLINE_AFTER_SECONDS', 90),
    ],

    'permissions' => [
        "pos" => [Action::Index, Action::KitchenViewer, Action::KitchenStations],
        "pos_registers" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        "pos_sessions" => [Action::Index, Action::Show, Action::Open, Action::Close],
        "pos_cash_movements" => [Action::Index, Action::Show, Action::Create],
        "pos_terminal_devices" => [Action::Index, Action::Edit],
        "onboarding_slides" => [Action::Index, Action::Create, Action::Edit, Action::Destroy],
    ],
];
