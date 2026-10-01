<?php

namespace Modules\Printer\Enum;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum PrinterProviderType: string
{
    use EnumTranslatable, EnumArrayable;

    case WindowsAgent = "windows_agent";
    case UbuntuAgent = "ubuntu_agent";
    case AndroidApp = "android_app";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "printer::enums.printer_provider_types";
    }
}
