<?php

namespace Modules\Invoice\Services\CreateCreditNote;

use Modules\Invoice\Enums\InvoicePurpose;
use Modules\Invoice\Models\Invoice;
use Modules\Order\Models\Order;
use Throwable;

interface CreateCreditNoteServiceInterface
{
    /**
     * Create invoice credit note
     *
     * @param Order $order
     * @param InvoicePurpose $purpose
     * @return Invoice|null
     * @throws Throwable
     */
    public function create(Order $order, InvoicePurpose $purpose = InvoicePurpose::Return): ?Invoice;
}
