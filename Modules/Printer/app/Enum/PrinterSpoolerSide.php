<?php

namespace Modules\Printer\Enum;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum PrinterSpoolerSide: string
{
    use EnumTranslatable, EnumArrayable;

    case OneSided = "one-sided";
    case TwoSidedLongEdge = "two-sided-long-edge";
    case TwoSidedShortEdge = "two-sided-short-edge";
    
    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "printer::enums.printer_spooler_color_sides";
    }
}
