<?php

namespace Modules\Loyalty\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum LoyaltyGiftStatus: string
{
    use EnumTranslatable, EnumArrayable;

    case Available = "available";
    case Used = "used";
    case Expired = "expired";
    case Revoked = "revoked";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return 'loyalty::enums.loyalty_gift_statuses';
    }
}
