<?php

use Modules\User\Enums\{PermissionAction as Action};

return [
    'permissions' => [
        "settings" => [Action::Edit],
        "delivery_settings" => [Action::Edit],
        "appearance" => [Action::Index, Action::Edit],
        "system_configurations" => [Action::Index, Action::Edit],
    ],
    'maintenance' => [
        'permission_command' => env('SETTING_MAINTENANCE_PERMISSION_COMMAND'),
        'supervisor_restart_command' => env('SETTING_MAINTENANCE_SUPERVISOR_RESTART_COMMAND'),
        'printer_restart_command' => env('SETTING_MAINTENANCE_PRINTER_RESTART_COMMAND'),
    ],
];
