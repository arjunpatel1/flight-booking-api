<?php

namespace Modules\Setting\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum AutoRefreshMode: string
{
    use EnumArrayable, EnumTranslatable;

    case SmartPolling = "smart_polling";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "setting::enums.auto_refresh_modes";
    }
}
