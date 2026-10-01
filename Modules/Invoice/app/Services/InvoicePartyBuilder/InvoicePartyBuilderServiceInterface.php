<?php

namespace Modules\Invoice\Services\InvoicePartyBuilder;

use Modules\Branch\Models\Branch;
use Modules\Invoice\Models\InvoiceParty;
use Modules\User\Models\User;

interface InvoicePartyBuilderServiceInterface
{
    /**
     * Create seller party
     * @param Branch $branch
     * @return InvoiceParty
     */
    public function createSeller(Branch $branch): InvoiceParty;

    /**
     * Create buyer party
     *
     * @param User|null $customer
     * @return InvoiceParty|null
     */
    public function createBuyer(?User $customer): ?InvoiceParty;
}
