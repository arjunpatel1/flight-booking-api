<?php

namespace Modules\Product\Enums;

/**
 * Veg / non-veg classification shown as the green, red or amber dot on
 * customer-facing menus. Distinct from ProductDietaryLabel, which carries
 * positive claims only and therefore cannot express "contains meat".
 */
enum ProductFoodType: string
{
    case Veg = 'veg';
    case NonVeg = 'non_veg';
    case Egg = 'egg';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
