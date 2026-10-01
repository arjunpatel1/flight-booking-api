<?php

namespace Modules\Order\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum OrderPaymentStatus: string
{
    use EnumTranslatable, EnumArrayable;

    case Unpaid = "unpaid";
    case Paid = "paid";
    case PartiallyPaid = "partially_paid";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "order::enums.order_payment_statuses";
    }

    /**
     * Determine if payment is Partially Paid
     *
     * @return bool
     */
    public function isPartiallyPaid(): bool
    {
        return $this == OrderPaymentStatus::PartiallyPaid;
    }

    /**
     * Determine if payment is paid
     *
     * @return bool
     */
    public function isPaid(): bool
    {
        return $this == OrderPaymentStatus::Paid;
    }

    /**
     * Determine if payment is Unpaid
     *
     * @return bool
     */
    public function isUnpaid(): bool
    {
        return $this == OrderPaymentStatus::Unpaid;
    }
}
