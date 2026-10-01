<?php

namespace Modules\Currency\Services;

use Swap\Swap;

readonly class CurrencyRateExchanger
{
    /**
     * Create a new CurrencyRateExchanger instance.
     *
     * @param Swap $swap
     */
    public function __construct(private Swap $swap)
    {
    }

    /**
     * Exchange to the latest currency rate.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @return float|null
     */
    public function exchange(string $fromCurrency, string $toCurrency): float|null
    {
        return $this->swap->latest("$fromCurrency/$toCurrency")->getValue();
    }
}
