<?php

namespace Modules\Translation\Services\Translation;

use Illuminate\Support\Facades\Cache;
use Modules\Translation\Models\Translation;

class TranslationService implements TranslationServiceInterface
{
    /** @implements */
    public function label(): string
    {
        return __("translation::translations.translation");
    }

    /** @implements */
    public function update(string $key, string $locale, string $value): bool
    {
        $saved = Translation::query()
            ->firstOrNew(['key' => $key])
            ->setTranslations('value', [$locale => $value])
            ->save();

        Cache::tags('translations')->flush();

        return $saved;
    }

    public function getAppTranslations(?string $locale = null): array
    {
        return Translation::appRetrieve($locale);
    }

    /** @implements */
    public function retrieve(): array
    {
        return Translation::retrieve();
    }
}
