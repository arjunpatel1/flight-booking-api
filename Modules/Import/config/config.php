<?php

use Modules\User\Enums\PermissionAction as Action;

return [
    'queue' => env('IMPORT_QUEUE', 'default'),
    'permissions' => [
        'imports' => [
            Action::Index,
            Action::Show,
            Action::Create,
            Action::Import,
        ],
    ],
];
