<?php

namespace Modules\Cart;

use Darryldecode\Cart\ItemCollection;
use Illuminate\Support\Collection;
use JsonSerializable;
use Modules\Option\Models\Option;
use Modules\Option\Models\OptionValue;
use Modules\Product\Models\Product;
use Modules\Support\Enums\PriceType;
use Modules\Support\Money;
use Modules\Tax\Models\Tax;
use Modules\Tax\Services\TaxCalculationService;
use stdClass;


class CartItem implements JsonSerializable
{
    /**
     * The ID of the cart item.
     *
     * @var string
     */
    public string $id;

    /**
     * Quantity of the cart item.
     *
     * @var int
     */
    public int $qty;

    /**
     * Underlying product of the cart item.
     *
     * @var Product
     */
    public Product $product;

    /**
     *
     * @var Product
     */
    public Product $item;

    /**
     * Options of the cart item.
     *
     * @var Collection
     */
    public Collection $options;

    /**
     * Taxes of the cart item.
     *
     * @var Collection
     */
    public Collection $taxes;

    /**
     * Actions of the cart item.
     *
     * @var array
     */
    public array $actions;

    public ?int $seatNumber;

    public ?int $courseNumber;

    /**
     * Order product
     *
     * @var CartOrderProduct|null
     */
    public ?CartOrderProduct $orderProduct = null;

    /**
     * Loyalty gift
     *
     * @var CartLoyaltyGift|null
     */
    public ?CartLoyaltyGift $loyaltyGift = null;


    /**
     * Create a new instance of CartItem
     *
     * @param Cart $cart
     * @param ItemCollection $item
     */
    public function __construct(protected Cart $cart, ItemCollection $item)
    {
        $attributes = $item->get('attributes');
        $this->id = $item->get('id');
        $this->qty = $item->get('quantity');
        $this->product = $attributes['product'];
        $this->item = $attributes['item'];
        $this->options = $attributes['options'];
        $this->actions = $attributes['actions'] ?? [];
        $this->seatNumber = isset($attributes['seat_number'])
            ? (int) $attributes['seat_number']
            : null;
        $this->courseNumber = isset($attributes['course_number'])
            ? (int) $attributes['course_number']
            : null;
        if (isset($attributes['order_product'])) {
            $this->orderProduct = new CartOrderProduct($attributes['order_product']);
        }

        if (isset($attributes['loyalty_gift'])) {
            $this->loyaltyGift = new CartLoyaltyGift($attributes['loyalty_gift']);
        }

        $this->taxes = $this->product
            ->taxes
            ->filter(fn(Tax $tax) => $this->cart->isTaxApplicable($tax))
            ->values();
    }

    /**
     * @return false|string
     */
    public function __toString()
    {
        return json_encode($this->jsonSerialize());
    }

    /**
     * @return array
     */
    public function jsonSerialize(): array
    {
        $data = [
            'id' => $this->id,
            'qty' => $this->qty,
            'item' => [
                "id" => $this->item->id,
                "name" => $this->item->name,
                "thumbnail" => $this->item->thumbnail?->preview_image_url,
                "allergens" => $this->item->allergens ?? [],
                "dietary_labels" => $this->item->dietary_labels ?? [],
            ],
            "actions" => array_values($this->actions),
            'seat_number' => $this->seatNumber,
            'course_number' => $this->courseNumber,
            'options' => $this->options->isNotEmpty()
                ? $this
                    ->options
                    ->keyBy('order')
                    ->map(fn(Option $option) => [
                        "id" => $option->id,
                        "name" => $option->name,
                        "type" => $option->type?->value,
                        "values" => $option
                            ->values
                            ->map(fn(OptionValue $value) => [
                                "id" => $value->id,
                                "label" => $value->label,
                                "price" => $value->price,
                            ])
                    ])
                : new stdClass(),
            'loyaltyGift' => $this->loyaltyGift,
            'unitPrice' => $this->unitPrice(),
            'subtotal' => $this->subtotal(),
            'taxTotal' => $this->taxTotal(),
            'taxes' => $this->taxes(),
            'total' => $this->total(),
        ];
        if (!is_null($this->orderProduct)) {
            $data['orderProduct'] = $this->orderProduct;
        }
        return $data;
    }

    /**
     * Calculate the unit price of the cart item.
     *
     * @return Money
     */
    public function unitPrice(): Money
    {
        return $this->hasGifted()
            ? new Money(0, $this->item->selling_price->currency())
            : $this->item->selling_price->add($this->optionsPrice());
    }

    /**
     * Check if any this item is gifted
     *
     * @return bool
     */
    public function hasGifted(): bool
    {
        return !is_null($this->loyaltyGift);
    }

    /**
     * Calculate the price of the options
     * of the cart item.
     *
     * @return Money
     */
    public function optionsPrice(): Money
    {
        return new Money($this->calculateOptionsPrice(), $this->product->currency);
    }

    /**
     * Calculate the price of the options.
     *
     * @return float
     */
    private function calculateOptionsPrice(): float
    {
        return (float)$this->options
            ->sum(fn($option) => $this->sumOfThePricesOfTheValuesOf($option));
    }

    /**
     * Calculate the sum of the prices of the
     * values of the given option.
     *
     * @param $option
     *
     * @return float
     */
    private function sumOfThePricesOfTheValuesOf($option): float
    {
        return (float)$option->values
            ->sum(function ($value) {
                return $value->price_type === PriceType::Fixed
                    ? $value->price->amount()
                    : take_percent(
                        $value->price,
                        $this->item->selling_price->amount()
                    );
            });
    }

    /**
     * Calculate the subtotal price of the cart item.
     *
     * @return Money
     */
    public function subtotal(): Money
    {
        return new Money(
            app(TaxCalculationService::class)->taxableAmount($this->grossSubtotalAmount(), $this->taxes),
            $this->product->currency
        );
    }

    /**
     * Calculate the total price of the cart item.
     *
     * @return Money
     */
    public function taxTotal(): Money
    {
        return new Money(
            $this->taxes()->sum(fn(CartTax $cartTax) => $cartTax->amount()->amount()),
            $this->product->currency
        );
    }

    /**
     * Retrieve all applied tax details as CartTax objects.
     *
     * @return Collection<CartTax>
     */
    public function taxes(): Collection
    {
        if (!$this->hasTax()) {
            return collect();
        }

        return app(TaxCalculationService::class)
            ->calculate($this->grossSubtotalAmount(), $this->taxes)
            ->map(fn(array $row) => new CartTax(
                cart: $this,
                tax: $row['tax'],
                currency: $this->product->currency,
                preCalculatedAmount: $row['amount'],
            ))
            ->values();

    }

    /**
     * Check if any tax condition exists in the cart.
     *
     * @return bool
     */
    public function hasTax(): bool
    {
        return $this->taxes->isNotEmpty();
    }

    /**
     * Calculate the total price of the cart item.
     *
     * @return Money
     */
    public function total(): Money
    {
        return $this->subtotal()->add($this->taxTotal());
    }

    private function grossSubtotalAmount(): float
    {
        return $this->unitPrice()->multiply($this->qty)->amount();
    }
}
