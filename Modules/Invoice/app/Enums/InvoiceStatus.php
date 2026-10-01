<?php

namespace Modules\Invoice\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum InvoiceStatus: string
{
    use EnumArrayable, EnumTranslatable;

    case Issued = 'issued';

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "invoice::enums.invoice_statuses";
    }
}
