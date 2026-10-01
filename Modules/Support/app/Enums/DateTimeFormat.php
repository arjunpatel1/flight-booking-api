<?php

namespace Modules\Support\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum DateTimeFormat: string
{
    use EnumArrayable, EnumTranslatable;

    case DateTime = 'dateTime';
    case Date = 'date';
    case Time = 'time';

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "support::enums.datetime_formats";
    }
}
