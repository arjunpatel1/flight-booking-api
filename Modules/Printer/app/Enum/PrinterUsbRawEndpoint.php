<?php

namespace Modules\Printer\Enum;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum PrinterUsbRawEndpoint: string
{
    use EnumTranslatable, EnumArrayable;

    case Out = "0x01";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "printer::enums.printer_usb_raw_endpoints";
    }
}
