<?php

use Modules\User\Enums\PermissionAction as Action;

return [
    'permissions' => [
        'financial_dashboard' => [
            Action::Index,
            Action::BranchAnalysis,
            Action::Export,
        ],
    ],
];
