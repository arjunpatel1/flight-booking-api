<?php

namespace Modules\Order\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum ReasonType: string
{
    use EnumTranslatable, EnumArrayable;

    case Cancellation = "cancellation";
    case Refund = "refund";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "order::enums.reason_types";
    }
}
