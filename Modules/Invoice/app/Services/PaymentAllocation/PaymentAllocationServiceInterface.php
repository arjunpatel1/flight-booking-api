<?php

namespace Modules\Invoice\Services\PaymentAllocation;

use Illuminate\Support\Collection;
use Modules\Invoice\Models\Invoice;

interface PaymentAllocationServiceInterface
{
    /**
     * Allocate payments for this invoice based on all related orders
     *
     * @param Invoice $invoice
     * @param Collection $orders
     * @return void
     */
    public function allocate(Invoice $invoice, Collection $orders): void;
}
