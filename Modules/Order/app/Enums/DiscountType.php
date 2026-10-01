<?php

namespace Modules\Order\Enums;

use Modules\Discount\Models\Discount;
use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;
use Modules\Voucher\Models\Voucher;

enum DiscountType: string
{
    use EnumTranslatable, EnumArrayable;

    case Discount = "discount";
    case Voucher = "voucher";

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "order::enums.discount_types";
    }

    /**
     * Get discount type from model
     *
     * @param string|null $model
     * @return DiscountType|null
     */
    public static function getTypeFromModel(?string $model = null): ?DiscountType
    {
        return match ($model) {
            Discount::class => DiscountType::Discount,
            Voucher::class => DiscountType::Voucher,
            default => null,
        };
    }


    /**
     * Check if a discount came from a Voucher.
     *
     * @return bool
     */
    public function isVoucher(): bool
    {
        return $this === DiscountType::Voucher;
    }

    /**
     * Check if it's a standard discount rule (Percentage or Fixed).
     *
     * @return bool
     */
    public function isDiscount(): bool
    {
        return $this === DiscountType::Discount;
    }
}
