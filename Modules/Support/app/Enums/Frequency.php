<?php

namespace Modules\Support\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum Frequency: string
{
    use EnumArrayable, EnumTranslatable;

    case Daily = "daily";
    case Weekly = "weekly";
    case Monthly = "monthly";
    
    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "support::enums.frequencies";
    }
}
