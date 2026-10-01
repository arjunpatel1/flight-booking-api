<?php

namespace Modules\Invoice\Services\TaxBuilder;


use Illuminate\Support\Collection;
use Modules\Invoice\Models\Invoice;
use Modules\Invoice\Models\InvoiceLine;
use Modules\Invoice\Models\InvoiceTax;
use Modules\Tax\Models\Tax;

class TaxBuilderService implements TaxBuilderServiceInterface
{
    /** @inheritDoc */
    public function createLineTaxes(Invoice $invoice, InvoiceLine $line, Collection $taxes): void
    {
        $codes = $this->taxCodes($taxes->pluck('tax_id')->all());

        foreach ($taxes as $tax) {
            InvoiceTax::query()
                ->create([
                    'name' => $tax->getTranslations('name'),
                    'rate' => $tax->rate,
                    'currency' => $tax->currency,
                    'currency_rate' => $tax->currency_rate,
                    'amount' => $tax->amount->amount(),
                    'type' => $tax->type,
                    'compound' => $tax->compound,
                    'invoice_id' => $invoice->id,
                    'invoice_line_id' => $line->id,
                    'tax_id' => $tax->tax_id,
                    'code' => $codes[$tax->tax_id] ?? '',
                ]);
        }

    }

    /** @inheritDoc */
    public function createInvoiceTaxes(Invoice $invoice, Collection $orders): void
    {
        $grouped = collect();

        $codes = $this->taxCodes(
            $orders->flatMap(fn ($order) => $order->taxes->pluck('tax_id'))->all()
        );

        foreach ($orders as $order) {
            foreach ($order->taxes as $orderTax) {
                $code = $codes[$orderTax->tax_id] ?? '';
                $key = $orderTax->tax_id . '-' . $code;

                $current = $grouped->get($key, [
                    'orderTax' => $orderTax,
                    'code' => $code,
                    'amount' => 0,
                ]);

                $current['amount'] += $orderTax->amount->amount();

                $grouped->put($key, $current);
            }
        }

        foreach ($grouped as $item) {
            $orderTax = $item['orderTax'];

            InvoiceTax::query()->create([
                'invoice_id' => $invoice->id,
                'invoice_line_id' => null,
                'tax_id' => $orderTax->tax_id,
                'code' => $item['code'],
                'name' => $orderTax->getTranslations('name'),
                'rate' => $orderTax->rate,
                'currency' => $orderTax->currency,
                'currency_rate' => $orderTax->currency_rate,
                'amount' => $item['amount'],
                'type' => $orderTax->type,
                'compound' => $orderTax->compound,
            ]);
        }
    }

    /**
     * Resolve tax codes by id, keyed for lookup.
     *
     * Invoices are built from historical orders, so the referenced tax may
     * since have been deactivated, soft-deleted, or moved outside the caller's
     * branch scope. Tax carries HasActiveStatus, SoftDeletes and HasBranch, so
     * `$orderTax->tax` returns null in all three cases — dereferencing it threw
     * "Attempt to read property 'code' on null" and aborted invoice creation.
     * Dropping the global scopes keeps the original code on the invoice, which
     * is what the document should reflect.
     *
     * Resolved in one query per call rather than per row to avoid an N+1.
     *
     * @param  array<int, int|null>  $taxIds
     * @return array<int, string>
     */
    private function taxCodes(array $taxIds): array
    {
        $taxIds = array_values(array_unique(array_filter($taxIds)));

        if ($taxIds === []) {
            return [];
        }

        return Tax::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $taxIds)
            ->pluck('code', 'id')
            ->all();
    }
}
