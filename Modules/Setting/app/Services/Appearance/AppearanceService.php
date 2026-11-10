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
            "system_title" => self::title($settings, 'appearance_system_title'),
            "admin_title" => self::title($settings, 'appearance_admin_title'),
            "browser_title" => self::title($settings, 'appearance_browser_title'),
            "footer_text" => $settings['appearance_footer_text'] ?? null,
            "login_welcome_text" => $settings['appearance_login_welcome_text'] ?? null,
            "login_background_image" => Media::getCacheMedia($settings['appearance_login_background_image'] ?? null)?->url,
            "theme" => $this->themeResolver->resolve($settings),
        ];
    }

    /**
     * A title setting, falling back to the App Name. App Name is translatable
     * (stored as ['en' => 'Balaji Travels', ...]); passing that array on showed
     * "[object Object]" in the Appearance form, so it is reduced to one string.
     */
    public static function title(array $settings, string $key): string
    {
        foreach ([$settings[$key] ?? null, $settings['app_name'] ?? null, config('app.name')] as $value) {
            $text = self::text($value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private static function text(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value[app()->getLocale()]
                ?? $value[(string) config('app.fallback_locale')]
                ?? collect($value)->first(fn ($text) => is_string($text) && trim($text) !== '');
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
