<?php

namespace Modules\Printer\Enum;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum PrinterSpoolerOrientation: string
{
    use EnumTranslatable, EnumArrayable;

    case Portrait = "portrait";
    case Landscape = "landscape";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "printer::enums.printer_spooler_orientations";
    }
}
