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

        // Each new tenant starts with its own copy of the starter airports,
        // airlines, aircraft and cabin classes.
        \Modules\Saas\Models\Tenant::created(
            fn (\Modules\Saas\Models\Tenant $tenant) => app(\Modules\Flight\Listeners\SeedTenantFlightCatalog::class)->handle($tenant)
        );

        $this->registerCustomerRateLimits();
    }

    /**
     * Flight customers used the staff 'login' limiter, which counts by an
     * `identifier` field they never send: every customer behind one IP (an
     * office, a mobile network) shared 3 attempts a minute across sign-up and
     * login. Each now has its own budget, counted per person where possible.
     * FlightCustomerAuthController still locks 5 wrong passwords per account.
     */
    private function registerCustomerRateLimits(): void
    {
        $tooMany = fn (string $message) => fn ($request, array $headers) => response()->json([
            'message' => $message,
            'errors' => ['code' => 'TOO_MANY_ATTEMPTS', 'retry_after' => (int) ($headers['Retry-After'] ?? 60)],
        ], 429, $headers);

        \Illuminate\Support\Facades\RateLimiter::for('flight-customer-login', fn (\Illuminate\Http\Request $request) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(10)
                ->by('flight-customer-login|' . \Illuminate\Support\Str::lower(trim((string) $request->input('login'))) . '|' . $request->ip())
                ->response($tooMany('Too many login attempts. Please wait a minute and try again.')),
            \Illuminate\Cache\RateLimiting\Limit::perMinute(60)
                ->by('flight-customer-login-ip|' . $request->ip())
                ->response($tooMany('Too many login attempts from this network. Please wait a minute and try again.')),
        ]);

        \Illuminate\Support\Facades\RateLimiter::for('flight-customer-register', fn (\Illuminate\Http\Request $request) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(10)
                ->by('flight-customer-register|' . \Illuminate\Support\Str::lower(trim((string) $request->input('email'))) . '|' . $request->ip())
                ->response($tooMany('Too many sign-up attempts. Please wait a minute and try again.')),
            \Illuminate\Cache\RateLimiting\Limit::perMinute(30)
                ->by('flight-customer-register-ip|' . $request->ip())
                ->response($tooMany('Too many sign-ups from this network. Please wait a minute and try again.')),
        ]);
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
