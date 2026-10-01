<?php


use Modules\User\Enums\{PermissionAction as Action};

return [
    'permissions' => [
        "inventories" => [Action::Analytics, Action::RecipeCosting],
        "suppliers" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        "units" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        "ingredients" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        "stock_movements" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy],
        "purchases" => [Action::Index, Action::Show, Action::Create, Action::Edit, Action::Destroy, Action::markAsReceived],
        "wastage" => [Action::Index, Action::Create],
    ],
];
