<?php

namespace Modules\Support\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum ExportType: string
{
    use EnumArrayable, EnumTranslatable;

    case Xlsx = "xlsx";
    case Xls = "xls";
    case Csv = "csv";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "support::enums.export_types";
    }
}
