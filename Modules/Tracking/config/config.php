<?php

use Modules\User\Enums\{PermissionAction as Action};

return [
    'permissions' => [
        "live_tracking" => [
            Action::Index,
            Action::Show,
        ],
        "live_orders" => [
            Action::Accept,
            Action::Reject,
            Action::Override,
        ],
    ],
];
