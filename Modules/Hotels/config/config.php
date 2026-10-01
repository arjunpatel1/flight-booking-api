<?php

use Modules\User\Enums\{PermissionAction as Action};

return [
    'name' => 'Hotels',
    'permissions' => [
        "hotels" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
    ],
];
