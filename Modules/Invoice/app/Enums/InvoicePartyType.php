<?php

namespace Modules\Invoice\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum InvoicePartyType: string
{
    use EnumArrayable, EnumTranslatable;

    case Seller = 'seller';
    case Buyer = 'buyer';

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "invoice::enums.invoice_party_types";
    }
}
