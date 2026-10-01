<?php

namespace Modules\Cart\Services\DiscountApplyService;

use Illuminate\Pipeline\Pipeline;
use Modules\Discount\Checkers\AlreadyApplied;
use Modules\Discount\Checkers\ApplicableBranch;
use Modules\Discount\Checkers\ApplicableCategories;
use Modules\Discount\Checkers\ApplicableCustomer;
use Modules\Discount\Checkers\ApplicableDay;
use Modules\Discount\Checkers\ApplicableHasItems;
use Modules\Discount\Checkers\ApplicableOrderType;
use Modules\Discount\Checkers\ApplicableProducts;
use Modules\Discount\Checkers\DiscountExists;
use Modules\Discount\Checkers\MaximumSpend;
use Modules\Discount\Checkers\MinimumSpend;
use Modules\Discount\Checkers\UsageLimitPerCustomer;
use Modules\Discount\Checkers\UsageLimitPerDiscount;
use Modules\Discount\Checkers\ValidDiscount;
use Modules\Discount\Models\Discount;
use Modules\Loyalty\Models\LoyaltyGift;
use Modules\Voucher\Checkers\UsageLimitPerVoucher;
use Modules\Voucher\Checkers\ValidVoucher;
use Modules\Voucher\Checkers\VoucherExists;
use Modules\Voucher\Models\Voucher;

class DiscountApplyService implements DiscountApplyServiceInterface
{
    /** @inheritDoc */
    public function applyVoucher(string $cart, string $code, ?LoyaltyGift $gift = null): void
    {
        $voucher = Voucher::findByCode($code, ['gift']);
        
        if (is_null($gift)) {
            $gift = $voucher?->gift;
        }

        resolve(Pipeline::class)
            ->send($voucher)
            ->through([
                VoucherExists::class,
                \Modules\Voucher\Checkers\AlreadyApplied::class . ':' . $cart,
                ValidVoucher::class,
                \Modules\Voucher\Checkers\ApplicableCustomer::class . ':' . $cart,
                \Modules\Voucher\Checkers\ApplicableHasItems::class . ':' . $cart,
                \Modules\Voucher\Checkers\ApplicableBranch::class . ':' . $cart,
                \Modules\Voucher\Checkers\MinimumSpend::class . ':' . $cart,
                \Modules\Voucher\Checkers\MaximumSpend::class . ':' . $cart,
                \Modules\Voucher\Checkers\ApplicableDay::class,
                \Modules\Voucher\Checkers\ApplicableOrderType::class . ':' . $cart,
                \Modules\Voucher\Checkers\ApplicableProducts::class . ':' . $cart,
                \Modules\Voucher\Checkers\ApplicableCategories::class . ':' . $cart,
                UsageLimitPerVoucher::class . ':' . $cart,
                \Modules\Voucher\Checkers\UsageLimitPerCustomer::class . ':' . $cart,
            ])
            ->then(fn(Voucher $voucher) => resolve($cart)::applyDiscount($voucher, $gift));
    }

    /** @inheritDoc */
    public function applyDiscount(string $cart, int $id, ?LoyaltyGift $gift = null): void
    {
        $discount = Discount::query()->with(['gift'])->find($id);

        if (is_null($gift)) {
            $gift = $discount?->gift;
        }

        resolve(Pipeline::class)
            ->send($discount)
            ->through([
                DiscountExists::class,
                AlreadyApplied::class . ':' . $cart,
                ValidDiscount::class,
                ApplicableCustomer::class . ':' . $cart,
                ApplicableHasItems::class . ':' . $cart,
                ApplicableBranch::class . ':' . $cart,
                MinimumSpend::class . ':' . $cart,
                MaximumSpend::class . ':' . $cart,
                ApplicableDay::class,
                ApplicableOrderType::class . ':' . $cart,
                ApplicableProducts::class . ':' . $cart,
                ApplicableCategories::class . ':' . $cart,
                UsageLimitPerDiscount::class . ':' . $cart,
                UsageLimitPerCustomer::class . ':' . $cart,
            ])
            ->then(fn(Discount $discount) => resolve($cart)::applyDiscount($discount, $gift));
    }
}
