<?php

namespace Modules\Currency\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Currency\Console\RefreshCurrencyRatesCommand;

class CurrencyServiceProvider extends ServiceProvider
{

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole() && setting('auto_refresh_currency_rates', false)) {
            $this->commands(RefreshCurrencyRatesCommand::class);
            $this->registerScheduler();
        }
    }

    private function registerScheduler(): void
    {
        $this->app->booted(function ($app) {
            $frequency = setting('auto_refresh_currency_rate_frequency');

            if (in_array($frequency, ['daily', 'weekly', 'monthly'])) {
                $app[Schedule::class]->command(RefreshCurrencyRatesCommand::class)->{$frequency}();
            }
        });
    }
}
