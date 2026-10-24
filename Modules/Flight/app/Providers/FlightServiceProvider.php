<?php

namespace Modules\Flight\Providers;

use Illuminate\Support\ServiceProvider;

class FlightServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'Flight';
    protected string $moduleNameLower = 'flight';

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(module_path($this->moduleName, 'database/migrations'));
        $this->loadViewsFrom(module_path($this->moduleName, 'resources/views'), 'flight');

        \Illuminate\Support\Facades\Event::subscribe(\Modules\Flight\Listeners\FlightNotificationSubscriber::class);
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(\Modules\Flight\Services\Supplier\SupplierHealthService::class);
        $this->app->singleton(\Modules\Flight\Services\Supplier\SupplierMappingService::class);
        $this->app->singleton(\Modules\Flight\Services\Supplier\SupplierManager::class);
        $this->app->singleton(\Modules\Flight\Services\Commission\FlightCommissionEngine::class);
        $this->app->singleton(\Modules\Flight\Services\B2B\FlightB2BNetworkService::class);
        $this->app->singleton(\Modules\Flight\Services\Wallet\FlightWalletLedgerService::class);
        $this->app->singleton(\Modules\Flight\Services\Subscription\FlightSubscriptionQuotaService::class);
        $this->app->singleton(\Modules\Flight\Services\Search\FlightSearchEngine::class);
        $this->app->singleton(\Modules\Flight\Services\Notification\Templates\FlightNotificationTemplateManager::class);
        $this->app->singleton(\Modules\Flight\Services\Notification\FlightNotificationService::class);
        $this->app->singleton(\Modules\Flight\Services\Webhook\FlightWebhookService::class);

        $this->app->register(DeferredFlightServiceProvider::class);
    }
}
