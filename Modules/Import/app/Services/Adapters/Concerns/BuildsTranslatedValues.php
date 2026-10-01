<?php

namespace Modules\Import\Services\Adapters\Concerns;

trait BuildsTranslatedValues
{
    /**
     * Read a boolean flag from an import row.
     *
     * A blank cell means "not supplied", so the column default applies.
     * filter_var() maps '' to false, which silently created is_active = false
     * records: the import reported success while the rows stayed invisible
     * behind the active scope.
     */
    protected function boolean(array $row, string $field, bool $default): ?bool
    {
        $raw = $row[$field] ?? null;

        if (is_null($raw) || trim((string) $raw) === '') {
            return $default;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }


    protected function translated(array $row, string $field, bool $required = true): array
    {
        $values = [];
        foreach (supportedLocaleKeys() as $locale) {
            $value = $row["{$field}_{$locale}"] ?? null;
            if (!is_null($value) && $value !== '') {
                $values[$locale] = $value;
            }
        }

        $defaultLocale = setting('default_locale');
        if ($required && empty($values[$defaultLocale])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                "{$field}_{$defaultLocale}" => [__('import::imports.errors.translation_required', [
                    'field' => $field,
                    'locale' => $defaultLocale,
                ])],
            ]);
        }

        return $values;
    }
}
