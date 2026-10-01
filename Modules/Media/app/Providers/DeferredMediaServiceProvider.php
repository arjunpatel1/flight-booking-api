<?php

namespace Modules\Media\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Media\Services\Media\MediaService;
use Modules\Media\Services\Media\MediaServiceInterface;

class DeferredMediaServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: MediaServiceInterface::class,
            concrete: fn($app) => $app->make(MediaService::class)
        );
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            MediaServiceInterface::class,
        ];
    }
}
