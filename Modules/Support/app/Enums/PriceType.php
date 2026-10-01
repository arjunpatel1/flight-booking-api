<?php

namespace Modules\Support\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum PriceType: string
{
    use EnumArrayable, EnumTranslatable;

    case Fixed = "fixed";
    case Percent = "percent";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "support::enums.price_types";
    }

    /**
     * Determine if price is percent
     *
     * @return bool
     */
    public function isPercent(): bool
    {
        return $this === PriceType::Percent;
    }

    /**
     * Determine if price is fixed
     *
     * @return bool
     */
    public function isFixed(): bool
    {
        return $this === PriceType::Fixed;
    }
}
