<?php

namespace Modules\Support\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum GroupDateType: string
{
    use EnumArrayable, EnumTranslatable;

    case Days = "days";
    case Weeks = "weeks";
    case Months = "months";
    case Years = "years";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "support::enums.group_date_types";
    }
}
