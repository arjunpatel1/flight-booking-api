<?php

namespace Modules\Printer\Enum;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum PrintJobStatus: string
{
    use EnumArrayable, EnumTranslatable;

    case Pending = "pending";
    case Success = "success";
    case Failed = "failed";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "printer::enums.print_job_statuses";
    }
}
