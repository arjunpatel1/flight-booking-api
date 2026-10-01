<?php

namespace Modules\Printer\Enum;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum PrintContentType: string
{
    use EnumArrayable, EnumTranslatable;

    case Invoice = 'invoice';
    case Bill = 'bill';
    case Kitchen = 'kitchen';
    case Waiter = 'waiter';
    case Delivery = 'delivery';

    public static function getTransKey(): string
    {
        return "printer::enums.print_content_types";
    }
}
