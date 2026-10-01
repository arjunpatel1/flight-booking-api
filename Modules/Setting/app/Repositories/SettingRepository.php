<?php

namespace Modules\Setting\Repositories;

use ArrayAccess;
use Illuminate\Support\Collection;
use Modules\Setting\Models\Setting;

class SettingRepository implements ArrayAccess
{
    /**
     * Collection of all settings.
     *
     * @var Collection
     */
    private Collection $settings;

    /**
     * Create a new repository instance.
     *
     * @param Collection $settings
     */
    public function __construct(Collection $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Label for the resource.
     *
     * @return string
     */
    public function label(): string
    {
        return __("setting::settings.settings.setting");
    }

    /**
     * Get all settings.
     *
     * @param array $except
     * @return array
     */
    public function all(array $except = []): array
    {
        return $this->settings->except($except)->all();
    }

    /**
     * Determine if a setting is existing.
     *
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->settings->has($offset);
    }

    /**
     * Unset a setting by the given key.
     *
     * @param mixed $offset
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->settings->forget($offset);
    }

    /**
     * Get setting for the given key.
     *
     * @param mixed $offset
     * @return mixed
     */
    public function __get(mixed $offset)
    {
        return $this->offsetGet($offset);
    }

    /**
     * Set a offset / value setting pair.
     *
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    public function __set(mixed $offset, mixed $value)
    {
        $this->offsetSet($offset, $value);
    }

    /**
     * Get setting for the given offset.
     *
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    /**
     * Get setting for the given key.
     *
     * @param mixed $offset
     * @param mixed $default
     * @return mixed
     */
    public function get(mixed $offset, mixed $default = null): mixed
    {
        return $this->settings->has($offset)
            ? $this->settings->get($offset)
            : $default;
    }

    /**
     * Set a key / value setting pair.
     *
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set([$offset => $value]);
    }

    /**
     * Set the given settings.
     *
     * @param array $settings
     * @return void
     */
    public function set(array $settings = []): void
    {
        Setting::setMany($settings);
    }
}
