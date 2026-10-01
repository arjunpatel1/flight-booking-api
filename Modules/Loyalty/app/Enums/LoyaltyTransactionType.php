<?php

namespace Modules\Loyalty\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum LoyaltyTransactionType: string
{
    use EnumArrayable, EnumTranslatable;

    case Earn = "earn";
    case Redeem = "redeem";
    case Adjust = "adjust";
    case Expire = "expire";
    case Bonus = "bonus";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return 'loyalty::enums.loyalty_transaction_types';
    }
}
