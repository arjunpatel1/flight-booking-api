<?php


use Modules\User\Enums\{PermissionAction as Action};

return [
    'permissions' => [
        "discounts" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
    ],
];
