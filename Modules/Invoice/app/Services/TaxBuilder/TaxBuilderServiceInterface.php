<?php

namespace Modules\Invoice\Services\TaxBuilder;

use Illuminate\Support\Collection;
use Modules\Invoice\Models\Invoice;
use Modules\Invoice\Models\InvoiceLine;

interface TaxBuilderServiceInterface
{
    /**
     * Create line taxes
     *
     * @param Invoice $invoice
     * @param InvoiceLine $line
     * @param Collection $taxes
     * @return void
     */
    public function createLineTaxes(Invoice $invoice, InvoiceLine $line, Collection $taxes): void;

    /**
     * Create invoice for taxes
     *
     * @param Invoice $invoice
     * @param Collection $orders
     * @return void
     */
    public function createInvoiceTaxes(Invoice $invoice, Collection $orders): void;
}
