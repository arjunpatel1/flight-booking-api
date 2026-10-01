<?php

namespace Modules\Pricing\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum PriceTypeRuleType: string
{
    use EnumTranslatable, EnumArrayable;

    case Flat = 'flat';
    case Percent = 'percent';
    case Fixed = 'fixed';

    public static function getTransKey(): string
    {
        return 'pricing::enums.price_type_rule_types';
    }
}
