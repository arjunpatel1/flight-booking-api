<?php

namespace Modules\Translation\Support;

use App\NexDine;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Translation\FileLoader;
use Modules\Translation\Models\Translation;

class TranslationLoader extends FileLoader
{
    /** @var array|string[] */
    protected array $defaultFiles = ['validation', 'auth', 'password', 'pagination'];

    /**
     * Get translation file paths.
     *
     * @return array
     */
    public function paths(): array
    {
        return array_merge($this->paths, $this->hints);
    }

    /**
     * Load the messages for the given locale.
     *
     * @param string $locale
     * @param string $group
     * @param string $namespace
     *
     * @return array
     */
    public function load($locale, $group, $namespace = null): array
    {
        return NexDine::cacheEnabled()
            ? Cache::tags('translations')
                ->rememberForever(
                    md5("translation_loader.$locale.$group.$namespace"),
                    fn() => $this->getTranslations($locale, $group, $namespace)
                )
            : $this->getTranslations($locale, $group, $namespace);
    }

    /**
     * Get file and database translations.
     *
     * @param string $locale
     * @param string $group
     * @param string|null $namespace
     *
     * @return array
     */
    private function getTranslations(string $locale, string $group, ?string $namespace = null): array
    {
        $databaseTranslations = $this->databaseTranslations($locale, $group, $namespace);

        return array_replace_recursive(
            $this->fileTranslations($locale, $group, $namespace),
            $this->breakDot($databaseTranslations)
        );
    }

    /**
     * Get database translations.
     *
     * @param string $locale
     * @param string $group
     * @param string|null $namespace
     *
     * @return array
     */
    private function databaseTranslations(string $locale, string $group, ?string $namespace = null): array
    {
        if (in_array($group, $this->defaultFiles) && $namespace == "*") {
            $key = "$group";
        } else {
            $key = "$namespace::$group";
        }

        return Translation::where('key', 'LIKE', "$key.%")
            ->whereLocale('value', $locale)
            ->get()
            ->mapWithKeys(function ($translation) use ($namespace, $group) {
                return [$translation->key => $translation->value];
            })
            ->all();
    }

    /**
     * Get file translations.
     *
     * @param string $locale
     * @param string $group
     * @param string|null $namespace
     *
     * @return array
     */
    private function fileTranslations(string $locale, string $group, ?string $namespace = null): array
    {
        return parent::load($locale, $group, $namespace);
    }

    /**
     * Break flattened dot translations to an array.
     *
     * @param array $translations
     *
     * @return array
     */
    private function breakDot(array $translations): array
    {
        $array = [];
        foreach ($translations as $key => $value) {
            if (in_array(explode(".", $key)[0], $this->defaultFiles)) {
                $key = str_replace(array_map(fn($newKey) => "$newKey.", $this->defaultFiles), '', $key);
                if (count(explode(".", $key)) > 2) {
                    Arr::set($array, $key, $value);
                } else {
                    $array[$key] = $value;
                }
            } else if (!str_contains($key, '*')) {
                Arr::set($array, $key, $value);
            } else {
                $array[$key] = $value;
            }
        }

        return $array;
    }
}
