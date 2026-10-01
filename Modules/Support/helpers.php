<?php

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Enums\Day;

if (!function_exists('locale')) {
    /**
     * Get current locale.
     *
     * @return string
     */
    function locale(): string
    {
        return app()->getLocale();
    }
}

if (!function_exists('fallbackLocale')) {
    /**
     * Get fallback locale.
     *
     * @return string
     */
    function fallbackLocale(): string
    {
        return config('app.fallback_locale');
    }
}

if (!function_exists('supportedLocaleKeys')) {
    /**
     * Get all supported locale keys.
     *
     * @return array
     */
    function supportedLocaleKeys(): array
    {
        return setting('supported_locales', [config('app.locale')]);
    }
}

if (!function_exists('supportedLanguages')) {
    /**
     * Get all supported languages.
     *
     * @return array
     */
    function supportedLanguages(): array
    {
        $languages = [];
        foreach (supportedLocaleKeys() as $locale) {
            $languages[] = [
                "id" => $locale,
                "name" => __("support::languages.{$locale}")
            ];
        }

        return $languages;
    }
}

if (!function_exists('makeCacheKey')) {
    /**
     * Generates an MD5 hash key, primarily used for cache keys.
     * This method was inspired by the video: https://www.youtube.com/watch?v=8g9TMWK3R34
     *
     * @param string|array $key The key to be hashed, which can be either a string or an array.
     * @param string $separator The separator symbol in case the key is array.
     * @param bool $withLocale
     * @return string The generated hash key.
     */
    function makeCacheKey(string|array $key, bool $withLocale = true, string $separator = '.'): string
    {
        $tenantId = null;
        if (class_exists(\Modules\Saas\Support\TenantContext::class) && app()->bound(\Modules\Saas\Support\TenantContext::class)) {
            $tenantId = app(\Modules\Saas\Support\TenantContext::class)->id();
        }

        $tenantId ??= auth()->check() ? (auth()->user()?->getAttributes()['tenant_id'] ?? null) : null;
        $keyString = $tenantId ? "tenant{$separator}{$tenantId}" : '';

        if (is_array($key)) {
            $key = array_filter($key);
            foreach ($key as $index => $value) {
                if (is_string($index)) {
                    $keyString .= $separator . $index . $separator . $value;
                } else {
                    $keyString .= $separator . $value;
                }
            }
        } else {
            $keyString .= $keyString === '' ? $key : $separator . $key;
        }

        $keyString = $withLocale ? "$keyString:" . locale() : $keyString;

        $keyString = Str::deduplicate($keyString, $separator);
        $keyString = Str::deduplicate($keyString, ':');

        return md5($keyString);
    }
}

if (!function_exists('parseIds')) {

    /**
     * return the IDs as an array
     *
     * @param array|string|int $ids
     * @param string $separator
     * @return array
     */
    function parseIds(array|string|int $ids, string $separator = ','): array
    {
        if (is_array($ids)) {
            return $ids;
        } else if (is_int($ids)) {
            return [$ids];
        }

        return array_map('intval', explode($separator, $ids));
    }
}

if (!function_exists('dateTimeFormat')) {
    /**
     * System date and time format
     *
     * @param Carbon|string|null $date
     * @param DateTimeFormat $case
     * @param string|null $default
     * @return string|null
     */
    function dateTimeFormat(
        Carbon|string|null $date = null,
        DateTimeFormat $case = DateTimeFormat::DateTime,
        ?string        $default = null
    ): ?string
    {
        if (is_string($date)) {
            try {
                $date = Carbon::parse($date);
            } catch (Throwable) {
                return $default;
            }
        }

        if (!is_null($date) && auth()->check() && auth()->user()->assignedToBranch()) {
            $date->timezone(auth()->user()->branch?->timezone);
        }

        $timeFormat = setting('default_time_format', 'h:i A');
        if (!is_null($date)) {
            switch ($case) {
                case DateTimeFormat::DateTime:
                    return $date->format(setting('default_date_format', 'Y-m-d') . " $timeFormat");
                case DateTimeFormat::Time:
                    return $date->format($timeFormat);
                case DateTimeFormat::Date:
                    return $date->format(setting('default_date_format', 'Y-m-d'));
            }
        }
        return $default;
    }
}

if (!function_exists('humanFileSize')) {
    /**
     * Get human-readable file size.
     *
     * @param $size
     * @param int $precision
     * @return string
     */
    function humanFileSize($size, int $precision = 2): string
    {
        $units = ['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        $step = 1024;
        $i = 0;
        while (($size / $step) > 0.9) {
            $size = $size / $step;
            $i++;
        }
        return round($size, $precision) . ' ' . $units[$i];
    }
}

if (!function_exists('array_reset_index')) {
    /**
     * Reset the numeric index of an array recursively.
     *
     * @param array|Collection $array
     *
     * @return array|Collection
     *
     */
    function array_reset_index(array|Collection $array): array|Collection
    {
        $array = $array instanceof Collection
            ? $array->toArray()
            : $array;

        foreach ($array as $key => $val) {
            if (is_array($val)) {
                $array[$key] = array_reset_index($val);
            }
        }

        if (isset($key) && is_numeric($key)) {
            return array_values($array);
        }

        return $array;
    }
}

if (!function_exists('startOfWeek')) {
    /**
     * Get start of week
     *
     * @return Carbon
     */
    function startOfWeek(): Carbon
    {
        return now()->startOfWeek(Day::from(setting('start_of_week', Day::Sunday->value))->carbonKey());
    }
}

if (!function_exists('endOfWeek')) {
    /**
     * Get end of week
     *
     * @return Carbon
     */
    function endOfWeek(): Carbon
    {
        return now()->endOfWeek(Day::from(setting('end_of_week', Day::Saturday->value))->carbonKey());
    }
}

if (!function_exists('printCenterText')) {

    /**
     * Print Center Text
     *
     * @param string $text
     * @param int $width
     * @return string
     */
    function printCenterText(string $text, int $width = 40): string
    {
        $text = trim($text);
        $padding = floor(($width - strlen($text)) / 2);
        return str_repeat(' ', max(0, (int)$padding)) . $text;
    }
}

if (!function_exists('take_percent')) {
    /**
     * Take the given percent of a given amount.
     *
     * @param int|float $percent
     * @param int|float $amount
     *
     * @return float
     */

    function take_percent(int|float $percent, int|float $amount): float
    {
        return ($percent / 100) * $amount;
    }
}
if (! function_exists('timestampDiffSql')) {
    /** Build a timestamp-difference expression for supported database drivers. */
    function timestampDiffSql(string $unit, string $from, string $to): string
    {
        $unit = strtoupper($unit);
        $factors = ['SECOND' => 86400, 'MINUTE' => 1440];

        if (! isset($factors[$unit])) {
            throw new InvalidArgumentException("Unsupported timestamp difference unit: {$unit}");
        }

        if (DB::getDriverName() === 'sqlite') {
            return "((julianday({$to}) - julianday({$from})) * {$factors[$unit]})";
        }

        return "TIMESTAMPDIFF({$unit}, {$from}, {$to})";
    }
}
if (!function_exists('getLogoDataBase64')) {
    /**
     * Get logo data as base64
     *
     * @return string|null
     */
    function getLogoBase64(): string|null
    {
        // Resolve the current tenant-scoped media first. The legacy
        // logo.base64 file is shared by every tenant on the host and can show
        // whichever restaurant uploaded a logo most recently.
        $logo = \Modules\Media\Models\Media::getCacheMedia(setting('logo'));
        if ($logo) {
            try {
                return 'data:'.($logo->mime_type ?: 'image/png').';base64,'.base64_encode($logo->getContent());
            } catch (\Throwable) {
                // Continue to the legacy/default fallback when storage is
                // temporarily unavailable.
            }
        }

        // A restaurant without a configured logo must not inherit the shared
        // NexDine/platform image in its customer invoice.
        if (app(\Modules\Saas\Support\TenantContext::class)->id()) {
            return null;
        }

        $disk = Storage::disk('local');
        $path = 'default-logo.base64';
        if (! $disk->exists($path)) {
            return null;
        }

        $logoBase64 = $disk->get($path);

        return $logoBase64 !== ''
            ? 'data:'.setting('logo_mime_type', 'image/svg+xml').';base64,'.$logoBase64
            : null;
    }
}
if (!function_exists('getInvoiceLogoBase64')) {
    /** Resolve the tenant's print-specific logo, falling back to its display logo. */
    function getInvoiceLogoBase64(): string|null
    {
        $logo = \Modules\Media\Models\Media::getCacheMedia(setting('invoice_logo'));
        if (! $logo) {
            return getLogoBase64();
        }

        try {
            return 'data:'.($logo->mime_type ?: 'image/png').';base64,'.base64_encode($logo->getContent());
        } catch (\Throwable) {
            return getLogoBase64();
        }
    }
}
