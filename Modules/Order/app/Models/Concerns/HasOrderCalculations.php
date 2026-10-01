<?php

namespace Modules\Order\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Modules\Cart\CartDiscount;
use Modules\Cart\CartItem;
use Modules\Currency\Currency;
use Modules\Loyalty\Services\LoyaltyGift\LoyaltyGiftServiceInterface;
use Modules\Order\Enums\DiscountType;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Models\OrderDiscount;
use Modules\Order\Models\OrderProduct;
use Modules\Support\Enums\PriceType;
use Modules\Support\Money;
use Modules\Voucher\Models\Voucher;

/**
 * Money attribute casts, line-item/discount persistence and total
 * recalculation for the Order model.
 */
trait HasOrderCalculations
{
    public function dueAmount(): Attribute
    {
        return Attribute::get(fn($amount) => isset($this->currency) ? new Money($amount, $this->currency) : Money::inDefaultCurrency($amount));
    }

    public function costPrice(): Attribute
    {
        return Attribute::get(fn($amount) => isset($this->currency) ? new Money($amount, $this->currency) : Money::inDefaultCurrency($amount));
    }

    public function revenue(): Attribute
    {
        return Attribute::get(fn($amount) => isset($this->currency) ? new Money($amount, $this->currency) : Money::inDefaultCurrency($amount));
    }

    public function subtotal(): Attribute
    {
        return Attribute::get(fn($amount) => isset($this->currency) ? new Money($amount, $this->currency) : Money::inDefaultCurrency($amount));
    }

    public function total(): Attribute
    {
        return Attribute::get(fn($amount) => isset($this->currency) ? new Money($amount, $this->currency) : Money::inDefaultCurrency($amount));
    }

    public function hasDiscount(): bool
    {
        return !is_null($this->discount);
    }

    /**
     * @throws \Throwable
     */
    public function updateOrCreateProduct(CartItem $cartItem): OrderProduct
    {
        $giftService = app(LoyaltyGiftServiceInterface::class);

        $params = [
            'product_id' => $cartItem->product->id,
            'currency' => $this->currency,
            'loyalty_gift_id' => $cartItem->loyaltyGift?->id(),
            'currency_rate' => $this->currency_rate,
            'quantity' => $cartItem->qty ?: 1,
            'seat_number' => $cartItem->seatNumber,
            'course_number' => $cartItem->courseNumber,
            'unit_price' => $cartItem->unitPrice(),
            'subtotal' => $cartItem->subtotal(),
            'tax_total' => $cartItem->taxTotal(),
            'total' => $cartItem->total()
        ];

        if (in_array($cartItem->orderProduct?->status(), [OrderProductStatus::Cancelled, OrderProductStatus::Refunded])) {
            $params['status'] = $cartItem->orderProduct->status();
        }

        if (!is_null($cartItem->orderProduct?->id())) {
            /** @var OrderProduct $orderProduct */
            $orderProduct = $this->products()
                ->where('id', $cartItem->orderProduct->id())
                ->first();
            $orderProduct->update($params);
        } else {
            /** @var OrderProduct $orderProduct */
            $orderProduct = $this->products()->create($params);
            $orderProduct->storeOptions($cartItem->options);
            if (!is_null($orderProduct->loyalty_gift_id)) {
                $giftService->useGift(loyaltyGiftId: $orderProduct->loyalty_gift_id, order: $this);
            }
        }

        $orderProduct->updateOrCreateTaxes($cartItem->taxes());

        return $orderProduct;
    }

    public function recalculate(bool $deleteTaxesDuplicates = false): void
    {
        if ($deleteTaxesDuplicates) {
            $this->deleteTaxesDuplicates();
        }

        $this->load(['taxes', 'products.product.categories', 'discount.discountable']);

        $subtotal = 0;
        $costPrice = 0;
        $revenue = 0;

        foreach ($this->products as $product) {
            $subtotal += $product->total->amount();
            $costPrice += $product->cost_price->amount();
            $revenue += $product->revenue->amount();
        }

        $totalTaxes = $this->recalculateTaxes($subtotal);

        $discountAmount = 0;
        $precision = Currency::subunit($this->currency);

        if (!is_null($this->discount) && $this->discount->discountable) {
            $discountable = $this->discount->discountable;

            $applicableProducts = $this->products->filter(function ($product) use ($discountable) {
                $productSkus = collect($discountable->conditions['products'] ?? []);
                $categories = collect($discountable->conditions['categories'] ?? []);

                $skuMatch = $productSkus->isEmpty() || $productSkus->contains($product->product->sku);
                $categoryMatch = $categories->isEmpty() || $categories->intersect($product->product->categories->pluck('slug'))->isNotEmpty();

                return $skuMatch && $categoryMatch;
            });

            $applicableSubtotal = $applicableProducts->sum(fn($product) => $product->total->amount());

            if ($discountable->type === PriceType::Percent) {
                $discountAmount = $applicableSubtotal * ($discountable->value / 100);
            } elseif ($discountable->type === PriceType::Fixed) {
                $discountAmount = min($discountable->value, $applicableSubtotal);
            }

            if (!is_null($discountable->max_discount) && $discountAmount > $discountable->max_discount->amount()) {
                $discountAmount = $discountable->max_discount->amount();
            }

            $this->discount->update(['amount' => round($discountAmount, $precision)]);
        }

        $additionalAmount = max(0, (float) collect(data_get($this->fulfilmentDetails(), 'additional_payments', []))->sum());
        $totalAfterDiscount = ($subtotal - $discountAmount) + $totalTaxes + $additionalAmount;

        $this->update([
            'subtotal' => round($subtotal, $precision),
            'total' => round($totalAfterDiscount, $precision),
            'cost_price' => round($costPrice, $precision),
            'revenue' => round($revenue, $precision),
        ]);

        $this->refreshDueAmount();
    }

    /**
     * @throws \Throwable
     */
    public function updateOrCreateDiscount(?CartDiscount $discount): void
    {
        /** @var OrderDiscount|null $orderDiscount */
        $orderDiscount = $this->discount;
        $oldLoyaltyGiftId = $orderDiscount?->loyalty_gift_id;

        $amount = $discount?->value();

        if (is_null($amount) || $amount->amount() == 0) {
            $discount = null;
        }

        $giftService = app(LoyaltyGiftServiceInterface::class);

        if (!is_null($oldLoyaltyGiftId) && $oldLoyaltyGiftId != $discount?->loyaltyGift()?->id()) {
            $giftService->rollbackGift(loyaltyGiftId: $oldLoyaltyGiftId, order: $this);
        }

        if ((is_null($discount) || $amount->isZero()) && $orderDiscount) {
            $orderDiscount->discountable?->unusedOnce();
            $orderDiscount->delete();
            return;
        }

        if (is_null($discount)) {
            return;
        }

        $discountModel = $discount->model();

        $data = [
            'discountable_type' => $discountModel->getMorphClass(),
            'discountable_id' => $discountModel->id,
            'type' => $discountModel instanceof Voucher
                ? DiscountType::Voucher
                : DiscountType::Discount,
            'currency' => $this->currency,
            'currency_rate' => $this->currency_rate,
            'loyalty_gift_id' => $discount->loyaltyGift()?->id(),
            'amount' => $amount->amount(),
        ];

        if ($orderDiscount) {
            $oldType = $orderDiscount->discountable_type;
            $oldId = $orderDiscount->discountable_id;

            $orderDiscount->update($data);

            if ($oldType !== $data['discountable_type'] || $oldId !== $data['discountable_id']) {
                $orderDiscount->discountable?->unusedOnce();
                $discountModel->usedOnce();
            }

            return;
        }


        /** @var OrderDiscount $orderDiscount */
        $orderDiscount = $this->discount()->create($data);
        $discountModel->usedOnce();

        if (!is_null($orderDiscount->loyalty_gift_id) && $oldLoyaltyGiftId != $orderDiscount->loyalty_gift_id) {
            $giftService->useGift(loyaltyGiftId: $orderDiscount->loyalty_gift_id, order: $this);
        }
    }
}
