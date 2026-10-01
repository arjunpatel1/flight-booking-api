<?php

use Modules\User\Enums\{PermissionAction as Action};

return [
    'permissions' => [
        "categories" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy]
    ],
];
