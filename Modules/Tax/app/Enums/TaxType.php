<?php

namespace Modules\Tax\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum TaxType: string
{
    use EnumTranslatable, EnumArrayable;

    case Inclusive = "inclusive";
    case Exclusive = "exclusive";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "tax::enums.tax_types";
    }

    /**
     * Returns true if the tax is inclusive.
     *
     * @return bool
     */
    public function isInclusive(): bool
    {
        return $this === TaxType::Inclusive;
    }

    /**
     * Returns true if the tax is exclusive.
     *
     * @return bool
     */
    public function isExclusive(): bool
    {
        return $this === TaxType::Exclusive;
    }
}
