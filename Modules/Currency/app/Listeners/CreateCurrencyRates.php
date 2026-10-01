<?php

namespace Modules\Currency\Listeners;

use Illuminate\Support\Facades\Schema;
use Modules\Currency\Models\CurrencyRate;
use Modules\Setting\Events\SettingSaved;

class CreateCurrencyRates
{
    /**
     * Handle the event.
     */
    public function handle(SettingSaved $event): void
    {
        if (! Schema::hasTable('currency_rates')) {
            return;
        }

        CurrencyRate::query()->insert($this->rates());
    }

    /**
     * Get the currency rates.
     */
    private function rates(): array
    {
        $currencyRates = CurrencyRate::query()->pluck('currency');

        return collect(request('supported_currencies', []))->filter()->reject(function ($currency) use ($currencyRates) {
            return $currencyRates->contains($currency);
        })->map(function ($currency) {
            return [
                'currency' => $currency,
                'rate' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->all();
    }
}
