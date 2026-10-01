<?php

namespace Modules\Cart;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use JsonSerializable;
use Modules\Discount\Models\Discount;
use Modules\Order\Enums\DiscountType;
use Modules\Support\Money;
use Modules\Voucher\Models\Voucher;

class CartDiscount implements Arrayable, JsonSerializable
{
    /**
     * Create a new instance of CartDiscount
     *
     * @param Cart|null $cart
     * @param Discount|Voucher|null $discount
     * @param CartCondition|null $condition
     */
    public function __construct(
        protected ?Cart                 $cart = null,
        protected Discount|Voucher|null $discount = null,
        protected ?CartCondition        $condition = null
    )
    {
    }

    /**
     * Get discount model
     *
     * @return Discount|Voucher|null
     */
    public function model(): Discount|Voucher|null
    {
        return $this->discount;
    }

    public function __toString()
    {
        return json_encode($this->jsonSerialize());
    }

    /** @inheritDoc */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'name' => $this->name(),
            'type' => $this->type()?->toTrans(),
            'value' => $this->value(),
            'loyaltyGift' => $this->loyaltyGift()
        ];
    }

    /**
     * Get discount id
     *
     * @return int|null
     */
    public function id(): ?int
    {
        return $this->discount?->id;
    }

    /**
     * Get discount name
     *
     * @return string|null
     */
    public function name(): ?string
    {
        return $this->type() === DiscountType::Voucher ? $this->discount?->code : $this->discount?->name;
    }

    /**
     * Get discount type
     *
     * @return DiscountType|null
     */
    public function type(): ?DiscountType
    {
        return DiscountType::getTypeFromModel($this->discount?->getMorphClass());
    }

    /**
     * Get discount value
     *
     * @return Money
     */
    public function value(): Money
    {
        $currency = $this->cart?->branch()->currency();

        // Darryldecode cart conditions represent discounts as negative values
        // (for example, "-10" or "-15%").  A discount exposed by this value
        // object is an amount to subtract, so keep it positive.  Returning the
        // signed condition value made Cart::total() subtract a negative number
        // and increased the net payable instead of reducing it.
        $amount = self::positiveAmount($this->calculate());
        $subTotal = $this->cart?->subTotal()->amount();

        if ($amount > $subTotal) {
            $amount = $subTotal;
        }

        if ($amount == 0) {
            $this->cart?->removeDiscount();
        }

        if (
            !is_null($this->discount)
            && !is_null($this->discount->max_discount)
            && $amount > $this->discount->max_discount->amount()
        ) {
            $amount = $this->discount->max_discount->amount();
        }

        return !is_null($currency) ? new Money($amount, $currency) : Money::inDefaultCurrency($amount);
    }

    private static function positiveAmount(float|int $amount): float
    {
        return abs((float) $amount);
    }

    /**
     * Discount calculate
     *
     * @return float
     */
    private function calculate(): float
    {
        if (is_null($this->discount)
            || !$this->applicableCustomer()
            || $this->didNotSpendTheRequiredAmount()
            || $this->spentMoreThanMaximumAmount()
            || $this->usageLimitReached()) {
            return 0;
        }

        $amount = $this->discountApplicableProductsTotalPrice();

        if ($amount == 0) {
            return 0;
        }

        return $this->condition
            ->getCalculatedValue($amount);
    }

    /**
     * Applicable for selected customer
     *
     * @return bool
     */
    public function applicableCustomer(): bool
    {
        if (empty($this->discount->meta['customer_id'])) {
            return true;
        }

        return $this->discount->meta['customer_id'] == $this->cart->customer()?->id();
    }

    /**
     * Did not spend the required amount
     *
     * @return bool
     */
    public function didNotSpendTheRequiredAmount(): bool
    {
        return $this->fresh()->discount->didNotSpendTheRequiredAmount($this->cart);
    }

    /**
     * Free discount
     *
     * @return $this
     */
    public function fresh(): static
    {
        $this->discount = $this->discount->refresh();

        return $this;
    }

    /**
     * Spent more than a maximum amount
     *
     * @return bool
     */
    public function spentMoreThanMaximumAmount(): bool
    {
        return $this->fresh()->discount->spentMoreThanMaximumAmount($this->cart);
    }

    /**
     * Usage limit reached
     *
     * @return bool
     */
    public function usageLimitReached(): bool
    {
        return $this->fresh()
            ->discount
            ->usageLimitReached(
                $this->cart->customer()?->id(),
                $this->cart->currentOrder()?->id()
            );
    }

    /**
     * Discount applicable products total price
     *
     * @return float
     */
    private function discountApplicableProductsTotalPrice(): float
    {
        return $this->discountApplicableProducts()
            ->sum(function ($cartItem) {
                return $cartItem->subtotal()->amount();
            });
    }

    /**
     * Discount applicable products
     * @return Collection
     */
    private function discountApplicableProducts(): Collection
    {
        return $this->cart->items()
            ->filter(function ($cartItem) {
                return $this->inApplicableProducts($cartItem);
            })->filter(function ($cartItem) {
                return $this->inApplicableCategories($cartItem);
            });

    }

    /**
     * In applicable products
     *
     * @param CartItem $cartItem
     * @return bool
     */
    private function inApplicableProducts(CartItem $cartItem): bool
    {
        $products = collect($this->discount->conditions['products'] ?? []);
        return $products->isEmpty() || $products->contains($cartItem->product->sku);
    }

    /**
     * In applicable categories
     *
     * @param CartItem $cartItem
     * @return bool
     */
    private function inApplicableCategories(CartItem $cartItem): bool
    {
        $categories = collect($this->discount->conditions['categories'] ?? []);

        return $categories->isEmpty() || $categories->intersect($cartItem->product->categories->pluck('slug'))
                ->isNotEmpty();

    }

    /**
     * Get loyalty gift
     *
     * @return CartLoyaltyGift|null
     */
    public function loyaltyGift(): ?CartLoyaltyGift
    {
        $loyaltyGift = $this->condition?->getAttribute('loyalty_gift');

        if (!is_null($loyaltyGift)) {
            return new CartLoyaltyGift($loyaltyGift);
        }

        return null;
    }

    public function __get($attribute)
    {
        return $this->discount?->{$attribute};
    }

    /**
     * Determine if a current discount is available or not
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->value()->amount() > 0;
    }
}
