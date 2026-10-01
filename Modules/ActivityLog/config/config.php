<?php

use Modules\User\Enums\{PermissionAction as Action};

return [
    'permissions' => [
        "activity_logs" => [Action::Index, Action::Show, Action::Destroy],
        "authentication_logs" => [Action::Index]
    ],
];
