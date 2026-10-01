<?php

namespace Modules\Support\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum Mailer: string
{
    use EnumArrayable, EnumTranslatable;

    case Smtp = "smtp";
    case Log = "log";
    case Array = "array";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "support::enums.mailers";
    }
}
