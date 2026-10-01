<?php

namespace Modules\Currency\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Currency\Services\CurrencyRate\CurrencyRateService;
use Modules\Currency\Services\CurrencyRate\CurrencyRateServiceInterface;

class DeferredCurrencyServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: CurrencyRateServiceInterface::class,
            concrete: fn($app) => $app->make(CurrencyRateService::class)
        );
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            CurrencyRateServiceInterface::class,
        ];
    }
}
