<?php

namespace Modules\Report\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum ExportMethod: string
{
    use EnumArrayable, EnumTranslatable;

    case Xlsx = "xlsx";
    case Xls = "xls";
    case Csv = "csv";
    case Json = 'json';

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return 'report::enums.export_method_types';
    }
}
