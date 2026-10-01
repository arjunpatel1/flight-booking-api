<?php

namespace Modules\Payment\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum PaymentStatus: string
{
    use EnumArrayable, EnumTranslatable;

    case Pending = "pending";
    case Completed = "completed";
    case Failed = "failed";
    case Refunded = "refunded";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "payment::enums.payment_statuses";
    }
}
