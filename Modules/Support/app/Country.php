<?php

namespace Modules\Support;

use Arr;

class Country
{
    /**
     * Path of the resource.
     *
     * @var string
     */
    const RESOURCE_PATH = __DIR__ . '/../lang/en/countries.php';

    /**
     * Array of all countries.
     *
     * @var array
     */
    private static array $countries;

    /**
     * Get all country codes.
     *
     * @return array
     */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    /**
     * Get all countries.
     *
     * @return array
     */
    public static function all(): array
    {
        if (!isset(self::$countries)) {
            $countries = __("support::countries") ?? [];
            self::$countries = empty($countries) || is_string($countries) ? require self::RESOURCE_PATH : $countries;
        }

        return self::$countries;
    }

    /**
     * Get countries as a list
     *
     * @return array
     */
    public static function toList(): array
    {
        return collect(static::all())
            ->map(fn($name, $tKey) => ['id' => $tKey, 'name' => $name])
            ->values()
            ->toArray();
    }

    /**
     * Get supported countries as a list
     *
     * @return array
     */
    public static function supportedList(): array
    {
        $countries = [];
        foreach (setting('supported_countries', []) as $countryCode) {
            $countries[] = [
                'id' => $countryCode,
                "name" => self::name($countryCode),
            ];
        }
        return $countries;
    }

    /**
     * Get a name of the given country code.
     *
     * @param string $code
     *
     * @return string
     */
    public static function name(string $code): string
    {
        return Arr::get(self::all(), $code);
    }
}
