<?php

namespace Modules\Currency\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum ExchangeService: string
{
    use EnumArrayable, EnumTranslatable;

    case Fixer = "fixer";
    case Forge = "forge";
    case CurrencyDataFeed = "currency_data_feed";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "currency::enums.exchange_services";
    }
}
