<?php

namespace Modules\Support\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum Day: string
{
    use EnumArrayable, EnumTranslatable;

    case Sunday = "sunday";
    case Monday = "monday";
    case Tuesday = "tuesday";
    case Wednesday = "wednesday";
    case Thursday = "thursday";
    case Friday = "friday";
    case Saturday = "saturday";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "support::enums.days";
    }

    /**
     * Get carbon key
     *
     * @return int
     */
    public function carbonKey(): int
    {
        return match ($this) {
            Day::Sunday => 0,
            Day::Monday => 1,
            Day::Tuesday => 2,
            Day::Wednesday => 3,
            Day::Thursday => 4,
            Day::Friday => 5,
            Day::Saturday => 6,
        };
    }
}
