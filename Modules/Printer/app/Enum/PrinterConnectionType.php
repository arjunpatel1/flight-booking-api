<?php

namespace Modules\Printer\Enum;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum PrinterConnectionType: string
{
    use EnumTranslatable, EnumArrayable;

    case Tcp = "tcp";
    case Spooler = "spooler";
    case UsbRaw = "usb_raw";
    case Bluetooth = "bluetooth";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "printer::enums.printer_connection_types";
    }
}
