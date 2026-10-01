<?php

namespace Modules\Printer\Enum;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum PrinterSpoolerColorMode: string
{
    use EnumTranslatable, EnumArrayable;

    case Color = "color";
    case Mono = "mono";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "printer::enums.printer_spooler_color_modes";
    }
}
