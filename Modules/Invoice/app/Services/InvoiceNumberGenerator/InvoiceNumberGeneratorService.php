<?php

namespace Modules\Invoice\Services\InvoiceNumberGenerator;

use Modules\Branch\Models\Branch;
use Modules\Invoice\Models\Invoice;

class InvoiceNumberGeneratorService implements InvoiceNumberGeneratorServiceInterface
{
    /** @inheritDoc */
    public function generate(Branch $branch, string $prefix = "INV"): array
    {
        $last = Invoice::query()
            // A deleted invoice number must never be issued again because the
            // database unique constraint still reserves it.
            ->withTrashed()
            ->where('branch_id', $branch->id)
            ->lockForUpdate()
            ->max('invoice_counter') ?? 0;

        $next = $last + 1;

        return [
            'counter' => $next,
            'number' => sprintf('%s-%02d-%05d', strtoupper($prefix), $branch->id, $next),
        ];
    }
}
