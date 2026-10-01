<?php

use Modules\User\Enums\{PermissionAction as Action};

return [
    'permissions' => [
        "menus" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        "online_menus" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
    ],
];
