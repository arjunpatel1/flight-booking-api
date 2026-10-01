<?php

namespace Modules\Inventory\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum PurchaseStatus: string
{
    use EnumArrayable, EnumTranslatable;

    case Draft = "draft";
    case Pending = "pending";
    case PartiallyReceived = "partially_received";
    case Received = "received";
    case Cancelled = "cancelled";


    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "inventory::enums.purchase_statuses";
    }
}
