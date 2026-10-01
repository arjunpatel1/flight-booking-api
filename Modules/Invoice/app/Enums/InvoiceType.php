<?php

namespace Modules\Invoice\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum InvoiceType: string
{
    use EnumArrayable, EnumTranslatable;

    case Standard = 'standard'; // Use this type if use is a business not personal
    case Simplified = 'simplified';

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "invoice::enums.invoice_types";
    }
}
