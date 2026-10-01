<?php

namespace Modules\Invoice\Services\InvoiceLineBuilder;

use Illuminate\Support\Collection;
use Modules\Invoice\Models\Invoice;

interface InvoiceLineBuilderServiceInterface
{
    /**
     * Create lines
     *
     * @param Invoice $invoice
     * @param Collection $orders
     * @return Collection
     */
    public function createLines(Invoice $invoice, Collection $orders): Collection;
}
