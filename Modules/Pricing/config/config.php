<?php

use Modules\User\Enums\PermissionAction as Action;

return [
    'permissions' => [
        'price_types' => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
    ],
];
