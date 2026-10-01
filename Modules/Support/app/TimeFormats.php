<?php

namespace Modules\Support;

class TimeFormats
{
    /**
     * Path of the resource.
     *
     * @var string
     */
    public const RESOURCE_PATH = __DIR__ . '/../Resources/time_formats.php';

    /**
     * Array of all formats.
     *
     * @var array
     */
    private static array $formats;

    /**
     * Get time format keys
     *
     * @return array
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * Get all formats.
     *
     * @return array
     */
    public static function all(): array
    {
        if (!isset(self::$formats)) {
            self::$formats = require self::RESOURCE_PATH;
        }

        return self::$formats;
    }

    /**
     * Get time formats as a list
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
}
