<?php

namespace Modules\Translation\Services\Translation;

use Illuminate\Database\Eloquent\ModelNotFoundException;

interface TranslationServiceInterface
{
    /**
     * Label for the resource.
     *
     * @return  string
     */
    public function label(): string;

    /**
     * Display a listing of the translations.
     *
     * @return array
     */
    public function retrieve(): array;

    /**
     * Get application translations
     *
     * @param string|null $locale
     * @return array
     */
    public function getAppTranslations(?string $locale = null): array;

    /**
     * Update the specified translation in storage.
     *
     * @param string $key
     * @param string $locale
     * @param string $value
     * @return bool
     * @throws ModelNotFoundException
     */
    public function update(string $key, string $locale, string $value): bool;
}
