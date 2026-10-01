<?php

namespace Modules\Currency\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Currency\Services\CurrencyRateExchanger;
use Swap\Builder;

class CurrencyExchangeRateServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->setupCurrencyRateExchangeService();

        $this->app->singleton(CurrencyRateExchanger::class, function () {
            $service = setting('currency_rate_exchange_service', 'array');
            $options = config("currency.services.$service");

            $swap = (new Builder)->add($service, $options)->build();

            return new CurrencyRateExchanger($swap);
        });
    }


    /**
     * Setup currency rate exchange service.
     *
     * @return void
     */
    private function setupCurrencyRateExchangeService(): void
    {
        config([
            'currency.services.fixer.access_key' => setting('fixer_access_key'),
            'currency.services.forge.api_key' => setting('forge_api_key'),
            'currency.services.currency_data_feed.api_key' => setting('currency_data_feed_api_key'),
        ]);
    }
}
