<?php

namespace Modules\User\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum GenderType: string
{
    use EnumArrayable, EnumTranslatable;

    case Male = "male";
    case Female = "female";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "user::enums.gender_types";
    }
}
