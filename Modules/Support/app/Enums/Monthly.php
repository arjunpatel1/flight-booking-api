<?php

namespace Modules\Support\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum Monthly: string
{
    use EnumArrayable, EnumTranslatable;

    case Week1 = "week_1";
    case Week2 = "week_2";
    case Week3 = "week_3";
    case Week4 = "week_4";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "support::enums.monthly";
    }
}
