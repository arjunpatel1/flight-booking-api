<?php

namespace Modules\Product\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum IngredientOperation: string
{
    use EnumArrayable, EnumTranslatable;

    case Add = "add";
    case Subtract = "subtract";
    case Replace = "replace";
    case Multiply = "multiply";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "product::enums.ingredient_operations";
    }
}
