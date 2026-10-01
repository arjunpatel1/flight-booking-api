<?php

namespace Modules\Aggregator\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum AggregatorSyncStatus: string
{
    use EnumArrayable, EnumTranslatable;

    case Pending = 'pending';
    case Processing = 'processing';
    case Success = 'success';
    case Failed = 'failed';
    case Retrying = 'retrying';
    case Cancelled = 'cancelled';
    case Ignored = 'ignored';
    case Skipped = 'skipped';

    public static function getTransKey(): string
    {
        return 'aggregator::enums.sync_statuses';
    }
}
