<?php

namespace Modules\Dashboard\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum AnalyticsPeriod: string
{
    use EnumArrayable, EnumTranslatable;

    case AllTime = 'all_time';
    case Today = 'today';
    case Yesterday = 'yesterday';
    case ThisWeek = 'this_week';
    case LastWeek = 'last_week';
    case ThisMonth = 'this_month';
    case ThisYear = 'this_year';

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "dashboard::enums.analytics_periods";
    }

    /**
     * Resolve the [start, end] datetime range for the period.
     * AllTime returns [null, null] (callers should skip the range filter).
     *
     * @return array{0: \Carbon\Carbon|null, 1: \Carbon\Carbon|null}
     */
    public function range(): array
    {
        return match ($this) {
            self::Today => [now()->startOfDay(), now()->endOfDay()],
            self::Yesterday => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            self::ThisWeek => [startOfWeek(), endOfWeek()],
            self::LastWeek => [startOfWeek()->subWeek(), endOfWeek()->subWeek()],
            self::ThisMonth => [now()->startOfMonth(), now()->endOfMonth()],
            self::ThisYear => [now()->startOfYear(), now()->endOfYear()],
            self::AllTime => [null, null],
        };
    }
}
