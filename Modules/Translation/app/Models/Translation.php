<?php

namespace Modules\Translation\Models;


use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasTagsCache;
use Modules\Translation\Support\PublicTranslationExceptPatterns;
use Spatie\Translatable\HasTranslations;
use Str;

class Translation extends Model
{
    use HasTranslations, HasActivityLog, HasTagsCache;

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['value'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'value'
    ];

    /**
     * Retrieve all translations.
     *
     * @return array
     */
    public static function retrieve(): array
    {
        $translations = [];

        foreach (Cache::tags('translations')
                     ->rememberForever(md5('translations.all'), function () {
                         return self::getAllTranslations();
                     })
                 as $key => $translation) {
            $translations[] = [
                "key" => $key,
                "translations" => $translation
            ];
        }

        return $translations;
    }

    /**
     * Get all translations
     *
     * @return array
     */
    protected static function getAllTranslations(): array
    {
        return array_replace_recursive(static::getFileTranslations(), static::getDatabaseTranslations());
    }

    /**
     * Get file translations.
     *
     * @return array
     */
    public static function getFileTranslations(): array
    {
        $translations = [];

        foreach (resolve('translation.loader')->paths() as $hint => $path) {
            foreach (supportedLocaleKeys() as $locale) {
                foreach (glob("{$path}/{$locale}/*.php") as $file) {
                    foreach (Arr::dot(require $file) as $key => $value) {
                        if ($hint == 1) {
                            continue;
                        }
                        $group = str_replace('.php', '', basename($file));
                        $translations[($hint == 0 ? '' : "{$hint}::") . "{$group}.{$key}"][$locale] = $value;
                    }
                }
            }
        }

        return $translations;
    }

    /**
     * Get database translations.
     *
     * @return array
     */
    public static function getDatabaseTranslations(): array
    {
        $translations = [];

        foreach (static::all() as $translation) {
            foreach ($translation->getTranslations('value') as $locale => $value) {
                $translations[$translation->key][$locale] = $value;
            }
        }

        return $translations;
    }

    /**
     * Retrieve all translations.
     *
     * @return array
     */
    public static function appRetrieve(?string $locale = null): array
    {
        $translations = [];
        $exceptPatterns = PublicTranslationExceptPatterns::$patterns;
        $locale = in_array($locale, supportedLocaleKeys(), true)
            ? $locale
            : setting('default_locale');
        $allTranslations = Cache::tags('translations')
            ->rememberForever(
                makeCacheKey([
                    "translations",
                    "public",
                    "all",
                ], false),
                fn() => self::getAllTranslations()
            );

        foreach ($allTranslations as $key => $translation) {
            $isExcluded = ! Str::is('voice::messages.*', $key)
                && collect($exceptPatterns)->contains(fn($pattern) => Str::is($pattern, $key));
            if ($isExcluded) {
                continue;
            }

            $translations[$locale][$key] = $translation[$locale]
                ?? ($translation[setting("default_locale")] ?? Arr::first($translation));
        }

        return $translations;
    }
}
