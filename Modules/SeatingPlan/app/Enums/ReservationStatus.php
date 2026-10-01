<?php

namespace Modules\SeatingPlan\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum ReservationStatus: string
{
    use EnumArrayable, EnumTranslatable;

    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Seated = 'seated';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public static function getTransKey(): string
    {
        return 'seatingplan::reservations.statuses';
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => '#f59e0b',
            self::Confirmed => '#2563eb',
            self::Seated => '#16a34a',
            self::Completed => '#64748b',
            self::Cancelled => '#ef4444',
            self::NoShow => '#7c2d12',
        };
    }
}
