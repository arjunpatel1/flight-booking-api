<?php

use Modules\User\Enums\PermissionAction as Action;

return [
    'permissions' => [
        'voice.settings' => [Action::Edit],
        'voice.templates' => [Action::Edit],
    ],
];
