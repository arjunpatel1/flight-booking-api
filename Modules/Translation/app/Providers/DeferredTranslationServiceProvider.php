<?php

namespace Modules\Translation\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Translation\Services\Translation\TranslationService;
use Modules\Translation\Services\Translation\TranslationServiceInterface;

class DeferredTranslationServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: TranslationServiceInterface::class,
            concrete: fn($app) => $app->make(TranslationService::class)
        );
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            TranslationServiceInterface::class,
        ];
    }
}
