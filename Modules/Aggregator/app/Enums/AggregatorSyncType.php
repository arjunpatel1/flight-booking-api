<?php

namespace Modules\Aggregator\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum AggregatorSyncType: string
{
    use EnumArrayable, EnumTranslatable;

    case Order = 'order';
    case Menu = 'menu';
    case Status = 'status';
    case Availability = 'availability';
    case Webhook = 'webhook';

    public static function getTransKey(): string
    {
        return 'aggregator::enums.sync_types';
    }
}
