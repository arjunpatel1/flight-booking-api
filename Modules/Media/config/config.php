<?php


use Modules\User\Enums\{PermissionAction as Action};

return [
    /*
     * Disk used to store & serve uploaded media. Must be a web-servable disk
     * (one that produces public URLs), e.g. "public" or "s3". Do NOT rely on
     * the app default disk (filesystems.default) — when it is "local" the files
     * land in storage/app/private and their URLs 404, so images never render.
     */
    'disk' => env('MEDIA_DISK', 'public'),

    /*
     * Non-image uploads can contain customer, employee, or financial data.
     * They are delivered only through the authenticated media endpoint and
     * must never be placed behind the public storage symlink.
     */
    'private_disk' => env('MEDIA_PRIVATE_DISK', 'local'),

    'permissions' => [
        "media" => [Action::Index, Action::Create, Action::Edit, Action::Destroy],
    ],
];
