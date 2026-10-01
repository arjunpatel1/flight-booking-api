<?php

namespace Modules\Tax\Services;

use Illuminate\Support\Collection;
use Modules\Tax\Enums\TaxType;

class TaxCalculationService
{
    /**
     * Return the taxable value for a gross amount and its configured taxes.
     *
     * Inclusive taxes are extracted from the gross amount. Exclusive taxes do not
     * reduce the taxable value because they are added on top of it.
     */
    public function taxableAmount(float $grossAmount, Collection $taxes): float
    {
        $inclusiveRate = $this->inclusiveTaxes($taxes)
            ->sum(fn($tax) => (float) ($tax->rate ?? 0));

        if ($inclusiveRate <= 0) {
            return $grossAmount;
        }

        return $grossAmount / (1 + ($inclusiveRate / 100));
    }

    /**
     * Calculate configured tax rows from a gross amount.
     *
     * The returned rows preserve each tax id, rate, type, and compound flag so
     * callers can persist an exact tax snapshot for orders/invoices/reports.
     */
    public function calculate(float $grossAmount, Collection $taxes): Collection
    {
        $orderedTaxes = $taxes
            ->values()
            ->sortBy(fn($tax) => (int) (bool) ($tax->compound ?? false))
            ->values();

        if ($orderedTaxes->isEmpty()) {
            return collect();
        }

        $taxableBase = $this->taxableAmount($grossAmount, $orderedTaxes);
        $rows = collect();

        foreach ($this->inclusiveTaxes($orderedTaxes) as $tax) {
            $rows->push([
                'tax' => $tax,
                'amount' => $taxableBase * (((float) ($tax->rate ?? 0)) / 100),
                'additive' => false,
            ]);
        }

        $previousExclusiveTax = 0.0;

        foreach ($this->exclusiveTaxes($orderedTaxes) as $tax) {
            $exclusiveBase = (bool) ($tax->compound ?? false)
                ? $taxableBase + $previousExclusiveTax
                : $taxableBase;
            $amount = $exclusiveBase * (((float) ($tax->rate ?? 0)) / 100);

            $rows->push([
                'tax' => $tax,
                'amount' => $amount,
                'additive' => true,
            ]);

            $previousExclusiveTax += $amount;
        }

        return $rows->values();
    }

    public function totalTax(float $grossAmount, Collection $taxes): float
    {
        return $this->calculate($grossAmount, $taxes)->sum('amount');
    }

    public function additiveTax(float $grossAmount, Collection $taxes): float
    {
        return $this->calculate($grossAmount, $taxes)
            ->where('additive', true)
            ->sum('amount');
    }

    private function inclusiveTaxes(Collection $taxes): Collection
    {
        return $taxes->filter(fn($tax) => ($tax->type ?? null) === TaxType::Inclusive)->values();
    }

    private function exclusiveTaxes(Collection $taxes): Collection
    {
        return $taxes->filter(fn($tax) => ($tax->type ?? null) === TaxType::Exclusive)->values();
    }
}
