<?php

namespace Modules\Invoice\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum InvoicePurpose: string
{
    use EnumArrayable, EnumTranslatable;

    case Original = 'original';
    case Cancellation = 'cancellation';
    case Return = 'return';


    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "invoice::enums.invoice_purposes";
    }
}
