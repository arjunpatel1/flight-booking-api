<?php

namespace Modules\Dashboard\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum SalesAnalyticsFilter: string
{
    use EnumArrayable, EnumTranslatable;

    case Weekly = 'weekly';
    case Monthly = 'monthly';

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "dashboard::enums.sales_analytics";
    }
}
