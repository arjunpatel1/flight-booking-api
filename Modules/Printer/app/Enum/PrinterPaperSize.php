<?php

namespace Modules\Printer\Enum;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum PrinterPaperSize: string
{
    use EnumTranslatable, EnumArrayable;

    case Paper58mm = "58mm";
    case Paper80mm = "80mm";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "printer::enums.printer_paper_sizes";
    }

    public function getAsNumber(): float
    {
        return match ($this) {
            PrinterPaperSize::Paper80mm => 80,
            PrinterPaperSize::Paper58mm => 58,
        };
    }
}
