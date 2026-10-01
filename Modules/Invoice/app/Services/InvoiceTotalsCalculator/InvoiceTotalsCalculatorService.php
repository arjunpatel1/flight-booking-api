<?php

namespace Modules\Invoice\Services\InvoiceTotalsCalculator;

use Illuminate\Support\Collection;
use Modules\Currency\Currency;
use Modules\Invoice\Models\Invoice;
use Modules\Invoice\Models\InvoiceLine;

class InvoiceTotalsCalculatorService implements InvoiceTotalsCalculatorServiceInterface
{
    /** @inheritDoc */
    public function applyTotals(Invoice $invoice, Collection $lines): void
    {
        $subtotal = round($lines->sum(fn(InvoiceLine $line) => $line->line_total_excl_tax->amount()), 4);
        $lineTax = round($lines->sum(fn(InvoiceLine $line) => $line->tax_amount->amount()), 4);

        $invoiceTax = round($invoice->taxes()->sum('amount'), 4);

        $taxTotal = round($lineTax + $invoiceTax, 4);

        $discountTotal = round($invoice->discounts()->sum('amount'), 4);

        $rawTotal = $subtotal + $taxTotal - $discountTotal;

        $totalRounded = round($rawTotal, Currency::subunit($invoice->currency));

        $rounding = round($totalRounded - $rawTotal, 4);

        $invoice->update([
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'discount_total' => $discountTotal,
            'total' => $rawTotal,
            'rounding_adjustment' => $rounding,
        ]);
    }
}
