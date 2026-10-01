<?php

namespace Modules\Cart\Services\DiscountApplyService;

use Modules\Loyalty\Models\LoyaltyGift;

interface DiscountApplyServiceInterface
{
    /**
     * Apply discount
     *
     * @param string $cart
     * @param int $id
     * @param LoyaltyGift|null $gift
     * @return void
     */
    public function applyDiscount(string $cart, int $id, ?LoyaltyGift $gift = null): void;

    /**
     * Apply voucher
     *
     * @param string $cart
     * @param string $code
     * @param LoyaltyGift|null $gift
     * @return void
     */
    public function applyVoucher(string $cart, string $code, ?LoyaltyGift $gift = null): void;
}
