<?php

use Modules\User\Enums\{PermissionAction as Action};

return [
    'permissions' => [
        "loyalty_programs" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        "loyalty_rewards" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        "loyalty_tiers" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        "loyalty_promotions" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        "loyalty_customers" => [Action::Index, Action::Show, Action::Adjust],
        "loyalty_transactions" => [Action::Index, Action::Show],
        "loyalty_gifts" => [Action::Index, Action::IndexRewards, Action::Redeem],
    ],
];
