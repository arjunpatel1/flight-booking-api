<?php

namespace Modules\SeatingPlan\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum TableMergeType: string
{
    use EnumArrayable, EnumTranslatable;

    case Order = "order";
    case Capacity = "capacity";
    case Billing = "billing";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "seatingplan::enums.table_merge_types";
    }
}
