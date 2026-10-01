<?php

use Modules\User\Enums\{PermissionAction as Action};

return [
    'providers' => [
        'msg91' => [
            'api_url' => env('WHATSAPP_MSG91_API_URL', 'https://api.msg91.com/api/v5/whatsapp/whatsapp-outbound-message/bulk/'),
        ],
        'nexmsg' => [
            'api_url' => env('WHATSAPP_NEXMSG_API_URL', 'https://api-nexmsg.myteknoland.com/api/send/template'),
        ],
    ],
    'permissions' => [
        "notifications" => [
            Action::Index,
            Action::Read,
            Action::Clear,
            Action::Logs,
            Action::Settings,
            Action::Dismiss,
            Action::Manage,
        ],
        "whatsapp" => [
            Action::Settings,
            Action::Templates,
            Action::Logs,
            Action::Send,
            Action::Broadcast,
            Action::DirectMessage,
            Action::Campaigns,
        ],
    ],
];
