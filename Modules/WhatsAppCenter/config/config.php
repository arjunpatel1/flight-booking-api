<?php

use Modules\User\Enums\PermissionAction as Action;

return [
    'webhooks' => [
        // One non-tenant secret used only for Meta's GET subscription
        // challenge. POST authenticity continues to use each profile's
        // encrypted webhook secret.
        'meta_verify_token' => env('WHATSAPP_META_VERIFY_TOKEN'),
    ],
    'nexmsg' => [
        // Fixed allow-listed origin; tenant input can never redirect secrets
        // or customer phone numbers to an arbitrary host.
        'catalog_send_url' => env('WHATSAPP_NEXMSG_CATALOG_SEND_URL', 'https://api-nexmsg.myteknoland.com/api/catalog/send'),
        'order_type_send_url' => env('WHATSAPP_NEXMSG_ORDER_TYPE_SEND_URL', 'https://api-nexmsg.myteknoland.com/api/catalog/order-type'),
        'location_request_send_url' => env('WHATSAPP_NEXMSG_LOCATION_REQUEST_SEND_URL', 'https://api-nexmsg.myteknoland.com/api/catalog/location-request'),
        'address_actions_send_url' => env('WHATSAPP_NEXMSG_ADDRESS_ACTIONS_SEND_URL', 'https://api-nexmsg.myteknoland.com/api/catalog/address-actions'),
        'text_send_url' => env('WHATSAPP_NEXMSG_TEXT_SEND_URL', 'https://api-nexmsg.myteknoland.com/api/send/text'),
        'template_send_url' => 'https://api-nexmsg.myteknoland.com/api/send/template',
        'templates_list_url' => 'https://api-nexmsg.myteknoland.com/api/templates',
        'catalog_sync_url' => env('WHATSAPP_NEXMSG_CATALOG_SYNC_URL', 'https://api-nexmsg.myteknoland.com/api/catalog/sync'),
        // The exact callback NexMsg must be configured with. Readiness checks
        // compare the provider-side value against this before going live.
        'trusted_webhook_url' => env('WHATSAPP_NEXMSG_TRUSTED_WEBHOOK_URL', 'https://api.nexdine.myteknoland.in/v1/whatsapp/webhook/nexmsg'),
    ],
    'permissions' => [
        'whatsapp_center' => [
            Action::Index,
            Action::Templates,
            Action::Schedule,
            Action::Send,
            Action::Logs,
        ],
        'whatsapp_ordering' => [
            Action::Index,
            Action::Show,
            Action::Edit,
            Action::Accept,
            Action::ReceivePayment,
        ],
    ],
];
