<?php

namespace Modules\Invoice\Services\CreateInvoice;

use Modules\Invoice\Enums\InvoicePurpose;
use Modules\Invoice\Models\Invoice;
use Modules\Order\Models\Order;
use Throwable;

interface CreateInvoiceServiceInterface
{
    /**
     * Create an invoice for the given order.
     *
     * @param Order $order
     * @param InvoicePurpose $purpose
     * @return Invoice
     * @throws Throwable
     */
    public function create(Order $order, InvoicePurpose $purpose = InvoicePurpose::Original): Invoice;
}
