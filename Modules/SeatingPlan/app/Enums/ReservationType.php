<?php

namespace Modules\SeatingPlan\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum ReservationType: string
{
    use EnumArrayable, EnumTranslatable;

    case Table = 'table';
    case Hall = 'hall';
    case Event = 'event';

    public static function getTransKey(): string
    {
        return 'seatingplan::reservations.types';
    }
}
