<?php

namespace Modules\Currency\Console;

use Illuminate\Console\Command;
use Modules\Currency\Models\CurrencyRate;
use Modules\Currency\Services\CurrencyRateExchanger;

class RefreshCurrencyRatesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'currency:refresh-rates';

    /**
     * The console command description.
     */
    protected $description = 'Refresh currency rates.';

    /**
     * Create a new command instance.
     *
     * @param CurrencyRateExchanger $exchanger
     *
     * @return void
     */
    public function __construct(private readonly CurrencyRateExchanger $exchanger)
    {
        parent::__construct();
    }
    
    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(): void
    {
        if (is_null(setting('currency_rate_exchange_service'))) {
            logger()->error('RefreshCurrencyRatesCommand: Currency rate exchange service is not configured.');
        } else {
            CurrencyRate::refreshRates($this->exchanger);
        }
    }
}
