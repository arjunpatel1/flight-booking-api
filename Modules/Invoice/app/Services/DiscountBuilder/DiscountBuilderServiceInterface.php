<?php

namespace Modules\Invoice\Services\DiscountBuilder;

use Illuminate\Support\Collection;
use Modules\Invoice\Models\Invoice;

interface DiscountBuilderServiceInterface
{
    /**
     * Create discounts
     *
     * @param Invoice $invoice
     * @param Collection $orders
     * @return Collection
     */
    public function createDiscounts(Invoice $invoice, Collection $orders): Collection;
}
