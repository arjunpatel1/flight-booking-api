<?php


use Modules\User\Enums\{PermissionAction as Action};

return [
    'permissions' => [
        "vouchers" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        "gift_cards" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy, Action::Redeem, Action::TopUp, Action::Analytics],
    ],
];
