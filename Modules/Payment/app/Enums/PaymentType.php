<?php

namespace Modules\Payment\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum PaymentType: string
{
    use EnumArrayable, EnumTranslatable;

    case Payment = 'payment';
    case Refund = 'refund';


    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "payment::enums.payment_types";
    }

    /**
     * Determine if is refund
     *
     * @return bool
     */
    public function isRefund(): bool
    {
        return $this === PaymentType::Refund;
    }
}
