<?php

namespace Modules\Invoice\Services\InvoiceLineBuilder;


use Illuminate\Support\Collection;
use Modules\Invoice\Models\Invoice;
use Modules\Invoice\Models\InvoiceLine;
use Modules\Invoice\Services\TaxBuilder\TaxBuilderServiceInterface;
use Modules\Order\Models\OrderProduct;

class InvoiceLineBuilderService implements InvoiceLineBuilderServiceInterface
{
    /**
     * Create a new instance of InvoiceLineBuilderService
     *
     * @param TaxBuilderServiceInterface $taxBuilder
     */
    public function __construct(protected TaxBuilderServiceInterface $taxBuilder)
    {

    }

    /** @inheritDoc */
    public function createLines(Invoice $invoice, Collection $orders): Collection
    {
        $lines = collect();

        foreach ($orders as $order) {
            /** @var OrderProduct $op */
            foreach ($order->products as $op) {
                $line = InvoiceLine::query()
                    ->create([
                        'invoice_id' => $invoice->id,
                        'order_product_id' => $op->id,
                        'description' => $op->product->getTranslations('name'),
                        'sku' => $op->product->sku,
                        'currency' => $op->currency,
                        'currency_rate' => $op->currency_rate,
                        'unit_price' => $op->unit_price->amount(),
                        'quantity' => $op->quantity,
                        'tax_amount' => $op->tax_total->amount(),
                        'line_total_excl_tax' => $op->subtotal->amount(),
                        'line_total_incl_tax' => $op->total->amount(),
                    ]);

                $this->taxBuilder->createLineTaxes($invoice, $line, $op->taxes);

                $lines->push($line);
            }

            foreach ((array) data_get($order->fulfilmentDetails(), 'additional_payments', []) as $chargeLabel => $additionalAmount) {
                $additionalAmount = max(0, (float) $additionalAmount);
                if ($additionalAmount <= 0) continue;
                $lines->push(InvoiceLine::query()->create([
                    'invoice_id' => $invoice->id,
                    'order_product_id' => null,
                    'description' => ['en' => str((string) $chargeLabel)->replace(['_', '-'], ' ')->title()->toString()],
                    'sku' => null,
                    'currency' => $order->currency,
                    'currency_rate' => $order->currency_rate,
                    'unit_price' => $additionalAmount,
                    'quantity' => 1,
                    'tax_amount' => 0,
                    'line_total_excl_tax' => $additionalAmount,
                    'line_total_incl_tax' => $additionalAmount,
                ]));
            }
        }

        return $lines;
    }
}
