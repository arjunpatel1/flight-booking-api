<?php

namespace Modules\Setting\Services\Appearance;

use Modules\Media\Models\Media;

class AppearanceService
{
    public function __construct(private readonly ThemeResolver $themeResolver)
    {
    }

    public function resolve(array $settings): array
    {
        return [
            "system_title" => $settings['appearance_system_title'] ?? $settings['app_name'] ?? config('app.name'),
            "admin_title" => $settings['appearance_admin_title'] ?? $settings['app_name'] ?? config('app.name'),
            "browser_title" => $settings['appearance_browser_title'] ?? $settings['app_name'] ?? config('app.name'),
            "footer_text" => $settings['appearance_footer_text'] ?? null,
            "login_welcome_text" => $settings['appearance_login_welcome_text'] ?? null,
            "login_background_image" => Media::getCacheMedia($settings['appearance_login_background_image'] ?? null)?->url,
            "theme" => $this->themeResolver->resolve($settings),
        ];
    }
}
