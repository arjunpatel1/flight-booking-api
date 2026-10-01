<?php

namespace Modules\Tax\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum GstType: string
{
    use EnumTranslatable, EnumArrayable;

    case CGST = 'CGST';
    case SGST = 'SGST';
    case IGST = 'IGST';
    case CESS = 'CESS';
    case None = 'None';

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "tax::enums.gst_types";
    }
}
