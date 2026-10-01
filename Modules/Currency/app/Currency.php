<?php

namespace Modules\Currency;

use Arr;

class Currency
{
    /**
     * Path of the resource.
     *
     * @var string
     */
    public const RESOURCE_PATH = __DIR__ . '/../Resources/currencies.php';

    /**
     * Array of all currencies.
     *
     * @var array
     */
    private static array $currencies;

    /**
     * Get all currency codes.
     *
     * @return array
     */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    /**
     * Get all currencies.
     *
     * @return array
     */
    public static function all(): array
    {
        if (!isset(self::$currencies)) {
            self::$currencies = require self::RESOURCE_PATH;
        }

        return self::$currencies;
    }

    /**
     * Get all currency names.
     *
     * @return array
     */
    public static function names(): array
    {
        return array_map(function ($currency) {
            return $currency['name'];
        }, self::all());
    }

    /**
     * Get subunit for the given currency code.
     *
     * @param string $code
     * @return int
     */
    public static function subunit(string $code): int
    {
        return Arr::get(self::all(), "$code.subunit");
    }

    /**
     * Get Currencies as a list
     *
     * @return array
     */
    public static function toList(): array
    {
        return collect(static::all())
            ->map(fn($name, $tKey) => ['id' => $tKey, 'name' => $name['name']])
            ->values()
            ->toArray();
    }

    /**
     * Get supported currencies as a list
     *
     * @return array
     */
    public static function supportedList(): array
    {
        $currencies = [];
        foreach (setting('supported_currencies', []) as $currency) {
            $currencies[] = [
                'id' => $currency,
                "name" => self::name($currency),
            ];
        }
        return $currencies;
    }

    /**
     * Get the name of the give currency code.
     *
     * @param string $code
     * @return string
     */
    public static function name(string $code): string
    {
        return Arr::get(self::all(), "$code.name");
    }

}
