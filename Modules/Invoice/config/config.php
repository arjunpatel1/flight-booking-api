<?php

use Modules\User\Enums\{PermissionAction as Action};

return [
    'permissions' => [
        "invoices" => [Action::Index, Action::Show],
    ],
];
