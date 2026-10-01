<?php

namespace Modules\Product\Enums;

/**
 * Dietary suitability labels a product can advertise (positive claims), shown
 * alongside allergen warnings on POS clients.
 */
enum ProductDietaryLabel: string
{
    case Vegetarian = 'vegetarian';
    case Vegan = 'vegan';
    case Halal = 'halal';
    case GlutenFree = 'gluten_free';
    case Spicy = 'spicy';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
