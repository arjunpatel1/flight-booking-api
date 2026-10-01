<?php

namespace Modules\Tax\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Tax\Services\Tax\TaxService;
use Modules\Tax\Services\Tax\TaxServiceInterface;

class DeferredTaxServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: TaxServiceInterface::class,
            concrete: fn($app) => $app->make(TaxService::class)
        );
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            TaxServiceInterface::class,
        ];
    }
}
