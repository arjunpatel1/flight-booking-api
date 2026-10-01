<?php

namespace Modules\Order\Enums;

use Modules\Support\Traits\EnumArrayable;

enum OrderProductAction: string
{
    use  EnumArrayable;

    case Cancel = "cancel";
    case Refund = "refund";

    /**
     * Get product status
     *
     * @return OrderProductStatus
     */
    public function getProductStatus(): OrderProductStatus
    {
        return match ($this) {
            OrderProductAction::Cancel => OrderProductStatus::Cancelled,
            OrderProductAction::Refund => OrderProductStatus::Refunded,
        };
    }
}
