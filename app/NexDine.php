<?php

namespace App;

use Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class NexDine
{
    /**
     * NexDine application version
     *
     * @var string
     */
    public static string $version = "1.5.2";

    /**
     * Determine if NexDine cache is enabled
     *
     * @return bool
     */
    public static function cacheEnabled(): bool
    {
        return (bool)config('cache.enabled');
    }

    /**
     * Determine if NexDine seed demo data is enabled
     *
     * @return bool
     */
    public static function seedDemoData(): bool
    {
        return (bool)config('app.seed_demo_data');
    }

    /**
     * Determine if NexDine app is installed
     *
     * @return bool
     */
    public static function installed(): bool
    {
        return (bool)config('app.installed');
    }

    /**
     * Get paginating per page
     *
     * @return int
     */
    public static function paginate(): int
    {
        try {
            $requestPerPage = max(
                request('per_page', 10),
                1
            );
            return (int)min($requestPerPage, 100);
        } catch (Exception) {
            return 10;
        }
    }

    /**
     * Check if route prefix is enabled
     *
     * @return bool
     */
    public static function routeDomainEnabled(): bool
    {
        return config('core.enable_route_domain', false);
    }

    /**
     * Determine if API requests all translations
     *
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function returnAllTranslations(): bool
    {
        return request()->get('return_all_translations') == 'true';
    }
}
