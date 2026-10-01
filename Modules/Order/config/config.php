<?php

use Modules\User\Enums\{PermissionAction as Action};

return [
    'permissions' => [
        "orders" => [
            Action::Index,
            Action::Show,
            Action::Create,
            Action::Edit,
            Action::Cancel,
            Action::Refund,
            Action::Active,
            Action::Upcoming,
            Action::ReceivePayment,
            Action::Complimentary,
            Action::Split,
            Action::UpdateStatus,
            Action::Financials,
            Action::Print,
            Action::Destroy,
        ],
        "cart" => [Action::Index, Action::Create, Action::Edit],
        "reasons" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy]
    ],
];
