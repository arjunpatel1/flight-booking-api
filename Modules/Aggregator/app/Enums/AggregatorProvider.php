<?php

namespace Modules\Aggregator\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum AggregatorProvider: string
{
    use EnumArrayable, EnumTranslatable;

    case Swiggy = 'swiggy';
    case Zomato = 'zomato';
    case Ondc = 'ondc';
    case Magicpin = 'magicpin';
    case Dunzo = 'dunzo';
    case Porter = 'porter';
    case Blinkit = 'blinkit';
    case UberEats = 'uber_eats';

    public static function getTransKey(): string
    {
        return 'aggregator::enums.providers';
    }
}
