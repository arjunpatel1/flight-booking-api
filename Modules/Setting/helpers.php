<?php

if (!function_exists('setting')) {
    /**
     * Get/Set the specified setting value.
     *
     * If an array is passed, we'll assume you want to set settings.
     *
     * @param string|array|null $key
     * @param mixed $default
     * @return mixed
     */
    function setting(string|array|null $key = null, mixed $default = null): mixed
    {
        if (is_null($key)) {
            return app('setting');
        }

        if (is_array($key)) {
            return app('setting')->set($key);
        }

        try {
            return app('setting')->get($key, $default);
        } catch (PDOException $e) {
            return $default;
        }
    }
}
