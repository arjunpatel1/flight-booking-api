<?php

namespace Modules\Invoice\Services\InvoicePartyBuilder;

use Modules\Branch\Models\Branch;
use Modules\Invoice\Enums\InvoicePartyType;
use Modules\Invoice\Models\InvoiceParty;
use Modules\User\Models\User;

class InvoicePartyBuilderService implements InvoicePartyBuilderServiceInterface
{
    /** @inheritDoc */
    public function createSeller(Branch $branch): InvoiceParty
    {
        return InvoiceParty::query()
            ->create([
                'type' => InvoicePartyType::Seller,
                'legal_name' => $branch->legal_name ?? $branch->name,
                'vat_tin' => $branch->vat_tin,
                'cr_number' => $branch->registration_number,
                'address_line1' => $branch->address_line1,
                'address_line2' => $branch->address_line2,
                'city' => $branch->city,
                'state' => $branch->state,
                'country_code' => $branch->country_code,
                'postal_code' => $branch->postal_code,
                'phone' => $branch->phone,
                'email' => $branch->email,
            ]);
    }

    /** @inheritDoc */
    public function createBuyer(?User $customer): ?InvoiceParty
    {
        if (!$customer || !$customer->id) {
            return null;
        }

        return InvoiceParty::query()
            ->create([
                'type' => InvoicePartyType::Buyer,
                'legal_name' => $customer->name,
                'phone' => $customer->phone,
                // Legacy / OTP-created customers can predate the country-code
                // field. An invoice must never make a completed POS payment
                // fail because of optional profile data.
                'country_code' => $customer->phone_country_iso_code ?: (setting('default_country_iso_code') ?: 'IN'),
                'email' => $customer->email,
            ]);
    }
}
