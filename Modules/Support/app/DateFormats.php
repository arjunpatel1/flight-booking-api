<?php

namespace Modules\Support;

class DateFormats
{
    /**
     * Path of the resource.
     *
     * @var string
     */
    public const RESOURCE_PATH = __DIR__ . '/../Resources/date_formats.php';

    /**
     * Array of all formats.
     *
     * @var array
     */
    private static array $formats;

    /**
     * Get date format keys
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
     * Get date formats as a list
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
