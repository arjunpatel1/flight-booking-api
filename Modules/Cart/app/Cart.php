<?php

namespace Modules\Cart;

use Darryldecode\Cart\Cart as DarryldecodeCart;
use Darryldecode\Cart\CartConditionCollection;
use Darryldecode\Cart\Exceptions\InvalidConditionException;
use Darryldecode\Cart\Exceptions\InvalidItemException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use JsonException;
use JsonSerializable;
use Modules\Branch\Models\Branch;
use Modules\Cart\Traits\CartInitOrder;
use Modules\Discount\Models\Discount;
use Modules\Loyalty\Models\LoyaltyGift;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderProduct;
use Modules\Pricing\Services\ProductPriceResolver\ProductPriceResolverServiceInterface;
use Modules\Product\Models\Product;
use Modules\Product\Services\ChosenProductOptions;
use Modules\Support\Money;
use Modules\Tax\Enums\TaxType;
use Modules\Tax\Models\Tax;
use Modules\Tax\Services\TaxCalculationService;
use Modules\User\Models\User;
use Modules\Voucher\Models\Voucher;


class Cart extends DarryldecodeCart implements JsonSerializable, Arrayable
{
    use CartInitOrder;

    /**
     * Cart Branch
     *
     * @var CartBranch|null
     */
    protected ?CartBranch $branch = null;

    protected ?Collection $itemsCache = null;

    protected ?Collection $taxesCache = null;

    protected ?CartDiscount $discountCache = null;

    protected ?Money $subTotalCache = null;

    protected ?Money $totalCache = null;

    protected ?Money $taxCache = null;

    /**
     * Get the current cart instance.
     *
     * @return static
     */
    public function instance(): static
    {
        return $this;
    }

    /**
     * Clear the cart and all attached conditions (e.g., taxes, discounts).
     *
     * @return void
     */
    public function clear(): void
    {
        parent::clear();
        $this->clearCartConditions();
        $this->flushComputedCache();
    }

    /**
     * Clear request-local totals/items caches after any cart mutation.
     */
    private function flushComputedCache(): void
    {
        $this->branch = null;
        $this->itemsCache = null;
        $this->taxesCache = null;
        $this->discountCache = null;
        $this->subTotalCache = null;
        $this->totalCache = null;
        $this->taxCache = null;
    }

    /**
     * Add or store a product in the cart.
     *
     * @param int $productId
     * @param int $qty
     * @param array $options
     * @param OrderProduct|null $orderProduct
     * @param LoyaltyGift|null $gift
     * @return void
     *
     * @throws InvalidItemException
     */
    public function store(
        int           $productId,
        int           $qty,
        array         $options = [],
        ?OrderProduct $orderProduct = null,
        ?LoyaltyGift  $gift = null,
        ?int          $tableId = null,
        ?int          $zoneId = null,
        ?Product      $loadedProduct = null,
        bool          $quickItem = false,
        ?int          $seatNumber = null,
        ?int          $courseNumber = null
    ): void
    {
        $options = array_filter($options);
        $seatNumber = $seatNumber !== null && $seatNumber > 0 ? min($seatNumber, 99) : null;
        $courseNumber = $courseNumber !== null && $courseNumber > 0 ? min($courseNumber, 20) : null;

        /** @var Product $product */
        $product = $loadedProduct ?: Product::with(['files', 'categories', 'taxes', 'menu', 'branch'])->findOrFail($productId);
        $product->loadMissing(['files', 'categories', 'taxes', 'menu', 'branch']);

        $priceResolver = app(ProductPriceResolverServiceInterface::class);
        $priceTypeId = $priceResolver->resolvePriceTypeIdForOrderType(
            orderType: $this->orderType()->value(),
            tableId: $tableId,
            zoneId: $zoneId
        );

        if ($priceTypeId && ! $quickItem) {
            $priceResolver->applyToProduct(
                product: $product,
                priceTypeId: $priceTypeId
            );
        }

        $existingItems = $this->getContent();

        if ($existingItems->isNotEmpty()) {
            $existingBranchIds = $existingItems
                ->pluck('attributes.branch_id')
                ->unique()
                ->filter();

            abort_if(
                $existingBranchIds->count() > 1 || ($existingBranchIds->first() !== $product->menu->branch_id),
                400,
                __("cart::messages.mixed_branch_not_allowed")
            );

            if (!is_null($gift)) {
                $giftAlreadyAdded = $existingItems
                    ->pluck('attributes.loyalty_gift.id')
                    ->unique()
                    ->filter();

                abort_if(
                    $giftAlreadyAdded->count(),
                    400,
                    __("cart::messages.gift_already_added")
                );
            }
        }

        $attributes = [
            'product' => $product,
            'branch_id' => $product->branch->id,
            'item' => $product,
            'options' => (new ChosenProductOptions($product, $options))->getEntities(),
            'price_type_id' => $priceTypeId,
            'quick_item' => $quickItem,
            'seat_number' => $seatNumber,
            'course_number' => $courseNumber,
            'created_at' => now()->valueOf(),
        ];

        $sellingPrice = $product->selling_price->amount();
        $uniqueId = "product_id.$productId:price_type_id." . ($priceTypeId ?: 'base')
            . ':seat.' . ($seatNumber ?: 'none')
            . ':course.' . ($courseNumber ?: 'none');

        if (!is_null($orderProduct)) {
            $uniqueId .= "status:{$orderProduct->status->value}:order_product_id:$orderProduct->id";
        }

        if (!is_null($gift)) {
            $uniqueId .= "loyalty_gift_id:$gift->id";
            $attributes['loyalty_gift'] = [
                'id' => $gift->id,
            ];
            $sellingPrice = 0;
        }

        $uniqueId = md5("$uniqueId:options." . serialize($options));

        if (!is_null($orderProduct)) {
            $attributes['order_product'] = [
                "id" => $orderProduct->id,
                "status" => $orderProduct->status
            ];
        }

        $this->add([
            'id' => $uniqueId,
            'name' => $product->name,
            'price' => $sellingPrice,
            'quantity' => $qty,
            'attributes' => $attributes,
        ]);

        $this->flushComputedCache();
    }

    /**
     * Rebuild current cart lines using the active order type pricing context.
     */
    public function repriceItems(?int $tableId = null, ?int $zoneId = null): void
    {
        $items = $this->getContent()->values();

        if ($items->isEmpty()) {
            return;
        }

        $snapshots = $items->map(function ($item) {
            $attributes = $item->get('attributes');

            return [
                'id' => $item->get('id'),
                'product_id' => $attributes['product']->id,
                'quantity' => (int) $item->get('quantity'),
                'options' => $this->selectedOptionsPayload($attributes['options'] ?? collect()),
                'order_product_id' => $attributes['order_product']['id'] ?? null,
                'loyalty_gift_id' => $attributes['loyalty_gift']['id'] ?? null,
                'quick_item' => (bool) ($attributes['quick_item'] ?? false),
                'seat_number' => isset($attributes['seat_number']) ? (int) $attributes['seat_number'] : null,
            ];
        });

        $snapshots->each(fn(array $snapshot) => $this->remove($snapshot['id']));

        $snapshots->each(function (array $snapshot) use ($tableId, $zoneId) {
            $this->store(
                productId: $snapshot['product_id'],
                qty: $snapshot['quantity'],
                options: $snapshot['options'],
                orderProduct: $snapshot['order_product_id'] ? OrderProduct::query()->find($snapshot['order_product_id']) : null,
                gift: $snapshot['loyalty_gift_id'] ? LoyaltyGift::query()->find($snapshot['loyalty_gift_id']) : null,
                tableId: $tableId,
                zoneId: $zoneId,
                quickItem: $snapshot['quick_item'] ?? false,
                seatNumber: $snapshot['seat_number'] ?? null,
            );
        });
    }

    /**
     * Convert selected option entities back to the payload shape accepted by ChosenProductOptions.
     */
    private function selectedOptionsPayload(Collection $options): array
    {
        return $options
            ->mapWithKeys(function ($option) {
                if (method_exists($option->type, 'isFieldType') && $option->type->isFieldType()) {
                    return [$option->id => $option->values->first()?->label];
                }

                return [$option->id => $option->values->pluck('id')->all()];
            })
            ->filter(fn($value) => filled($value))
            ->all();
    }

    /**
     * Get total count of items (unique cart lines, not quantity sum).
     *
     * @return int
     */
    public function count(): int
    {
        return $this->items()->count();
    }

    /**
     * Retrieve all cart items sorted by creation time (latest first).
     *
     * @return Collection<CartItem>
     */
    public function items(): Collection
    {
        return $this->itemsCache ??= $this->getContent()
            ->sortByDesc(fn($item) => $item->get('attributes')['created_at'])
            ->map(fn($item) => new CartItem($this, $item))
            ->values();
    }

    /**
     * Update the quantity of a specific cart item.
     *
     * @param string $id
     * @param int $qty
     * @return void
     */
    public function updateQuantity(string $id, int $qty): void
    {
        $this->update($id, [
            'quantity' => [
                'relative' => false,
                'value' => $qty,
            ],
        ]);

        $this->flushComputedCache();
    }

    public function updateSeatNumber(string $id, ?int $seatNumber): void
    {
        $item = $this->get($id);
        $seatNumber = $seatNumber !== null && $seatNumber > 0 ? min($seatNumber, 99) : null;

        $this->update($id, [
            'attributes' => [
                ...$item->attributes,
                'seat_number' => $seatNumber,
            ],
        ]);

        $this->flushComputedCache();
    }

    public function updateCourseNumber(string $id, ?int $courseNumber): void
    {
        $item = $this->get($id);
        $courseNumber = $courseNumber !== null && $courseNumber > 0 ? min($courseNumber, 20) : null;

        $this->update($id, [
            'attributes' => [
                ...$item->attributes,
                'course_number' => $courseNumber,
            ],
        ]);

        $this->flushComputedCache();
    }

    public function remove($id)
    {
        $removed = parent::remove($id);

        $this->flushComputedCache();

        return $removed;
    }

    /**
     * Convert the cart to JSON string.
     *
     * @return string
     * @throws JsonException
     */
    public function __toString(): string
    {
        return json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR);
    }

    /**
     * @inheritDoc
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items(),
            'quantity' => $this->getTotalQuantity(),
            'subTotal' => $this->subTotal(),
            'orderType' => $this->orderType(),
            'taxes' => $this->taxes(),
            'discount' => $this->discount(),
            'customer' => $this->customer(),
            'order' => $this->order(),
            'total' => $this->total(),
        ];
    }

    /**
     * Get the subtotal including option prices.
     *
     * @return Money
     */
    public function subTotal(): Money
    {
        return $this->subTotalCache ??= (new Money(
            $this->items()->sum(fn(CartItem $item) => $item->subtotal()->amount()),
            $this->branch()->currency()
        ))
            ->add($this->productsTaxesPrice());
    }

    /**
     * Get branch for this cart
     *
     * @return CartBranch|null
     */
    public function branch(): ?CartBranch
    {
        if (isset($this->branch)) {
            return $this->branch;
        }
        return $this->branch = new CartBranch($this->getConditionsByType('branch')->first());
    }

    /**
     * Calculate the total price of all option values.
     *
     * @return Money
     */
    private function optionsPrice(): Money
    {
        return new Money($this->calculateOptionsPrice(), $this->branch()->currency());
    }

    /**
     * Compute the numeric total of all options.
     *
     * @return float
     */
    private function calculateOptionsPrice(): float
    {
        return $this->items()->sum(fn(CartItem $item) => $item->optionsPrice()->multiply($item->qty)->amount());
    }

    /**
     * Calculate the total price of all product's taxes price.
     *
     * @return Money
     */
    private function productsTaxesPrice(): Money
    {
        return new Money($this->calculateProductsTaxesPrice(), $this->branch()->currency());
    }

    /**
     * Compute the numeric total of all products taxes.
     *
     * @return float
     */
    private function calculateProductsTaxesPrice(): float
    {
        return $this->items()->sum(fn(CartItem $item) => $item->taxTotal()->amount());
    }

    public function orderType(): CartOrderType
    {
        return new CartOrderType($this->getConditionsByType('order_type')->first());
    }

    /**
     * Retrieve all applied tax details as CartTax objects.
     *
     * @return Collection<CartTax>
     */
    public function taxes(): Collection
    {
        if ($this->taxesCache instanceof Collection) {
            return $this->taxesCache;
        }

        $branch = $this->branch();

        if ($this->hasTax()) {
            $taxConditions = $this->getConditionsByType('tax');

            /** @var Collection<Tax> $taxes */
            $taxes = Tax::query()
                ->whereIn('id', $this->getTaxIds($taxConditions))
                ->withOutGlobalBranchPermission()
                ->where('branch_id', $branch->id())
                ->orderBy("compound")
                ->global()
                ->get();
        } else {
            $taxes = $this->findTaxes();
        }

        return $this->taxesCache = app(TaxCalculationService::class)
            ->calculate($this->subtotal()->amount(), $taxes)
            ->map(fn(array $row) => new CartTax(
                cart: $this,
                tax: $row['tax'],
                currency: $branch->currency(),
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
        return $this->getConditionsByType('tax')->isNotEmpty();
    }

    /**
     * Extract tax  IDs from the given conditions.
     *
     * @param CartConditionCollection<CartCondition> $taxConditions
     * @return Collection<int>
     */
    private function getTaxIds(CartConditionCollection $taxConditions): Collection
    {
        return $taxConditions->map(fn(CartCondition $c) => $c->getAttribute('tax_id'));
    }

    /**
     * Get discount
     *
     * @return CartDiscount
     */
    public function discount(): CartDiscount
    {
        if ($this->discountCache instanceof CartDiscount) {
            return $this->discountCache;
        }

        if (!$this->hasDiscount()) {
            return $this->discountCache = new CartDiscount();
        }

        /** @var CartCondition $discountCondition */
        $discountCondition = $this->getConditionsByType('discount')->first();

        $cartDiscount = new CartDiscount(
            $this,
            resolve($discountCondition->getAttribute('model'))::query()
                ->find($discountCondition->getAttribute('discount_id')),
            $discountCondition
        );

        if (!$cartDiscount->isAvailable()) {
            $this->removeDiscount();
            return $this->discountCache = new CartDiscount();
        }

        return $this->discountCache = $cartDiscount;
    }

    /**
     * Determine if cart has discount or not
     *
     * @return bool
     */
    public function hasDiscount(): bool
    {
        if ($this->getConditionsByType('discount')->isEmpty()) {
            return false;
        }

        /** @var CartCondition $discount */
        $discount = $this->getConditionsByType('discount')
            ->first();

        return resolve($discount->getAttribute('model'))::query()
            ->where('id', $discount->getAttribute('discount_id'))
            ->exists();
    }

    /**
     * Determine if the cart is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->items()->isEmpty();
    }

    /**
     * Remove Discount from cart
     * @return void
     */
    public function removeDiscount(): void
    {
        $this->removeConditionsByType('discount');
        $this->flushComputedCache();
    }

    public function customer(): ?CartCustomer
    {
        return $this->hasCustomer()
            ? new CartCustomer($this->getConditionsByType('customer')->first())
            : null;
    }

    public function hasCustomer(): bool
    {
        return $this->getConditionsByType('customer')->isNotEmpty();
    }

    /**
     * Get the total including taxes.
     *
     * @return Money
     */
    public function total(): Money
    {
        return $this->totalCache ??= $this->subTotal()
            ->subtract(subtrahend: $this->discount()->value())
            ->add($this->additiveTax());
    }

    /**
     * Calculate total tax value for the cart.
     *
     * @return Money
     */
    public function tax(): Money
    {
        return $this->taxCache ??= new Money($this->calculateTax(), $this->branch()->currency());
    }

    /**
     * Compute numeric tax amount.
     *
     * @return float
     */
    private function calculateTax(): float
    {
        return $this->taxes()->sum(fn(CartTax $cartTax) => $cartTax->amount()->amount());
    }

    private function additiveTax(): Money
    {
        return new Money(
            $this->taxes()
                ->filter(fn(CartTax $cartTax) => $cartTax->type()->isExclusive())
                ->sum(fn(CartTax $cartTax) => $cartTax->amount()->amount()),
            $this->branch()->currency()
        );
    }

    /**
     * Determine if cart has branch or not
     *
     * @return bool
     */
    public function hasBranch(): bool
    {
        return $this->getConditionsByType('branch')->isNotEmpty();
    }

    /**
     * Determine if cart has order type or not
     *
     * @return bool
     */
    public function hasOrderType(): bool
    {
        return $this->getConditionsByType('order_type')->isNotEmpty();
    }

    /**
     * @throws InvalidConditionException
     */
    public function addBranch(Branch $branch): CartBranch
    {
        $this->removeBranch();

        $this->condition(
            new CartCondition([
                'name' => $branch->name,
                'type' => 'branch',
                'value' => $branch->id,
                'attributes' => [
                    'currency' => $branch->currency,
                ],
            ]),
        );

        if ($this->hasOrderType()) {
            $this->addTaxes();
        }

        return $this->branch();
    }

    public function removeBranch(): void
    {
        $this->removeConditionsByType('branch');
        $this->flushComputedCache();
    }

    /**
     * @throws InvalidConditionException
     */
    public function addOrderType(OrderType $type): CartOrderType
    {
        $this->removeOrderType();

        $this->condition(
            new CartCondition([
                'name' => $type->trans(),
                'type' => 'order_type',
                'value' => $type->value,
            ]),
        );

        $this->addTaxes();

        return $this->orderType();
    }

    public function removeOrderType(): void
    {
        $this->removeConditionsByType('order_type');
        $this->flushComputedCache();
    }

    /**
     * Add tax conditions to the cart.
     *
     * @return void
     *
     * @throws InvalidConditionException
     */
    public function addTaxes(): void
    {
        $this->removeTaxes();

        $this->findTaxes()
            ->each(function (Tax $tax) {
                $this->condition(
                    new CartCondition([
                        'name' => $tax->name,
                        'type' => 'tax',
                        'target' => 'total',
                        'value' => ($tax->type === TaxType::Inclusive ? '-' : '+') . "$tax->rate%",
                        'order' => $tax->compound ? 5 : 3,
                        'attributes' => [
                            'tax_id' => $tax->id,
                            'type' => $tax->type,
                            'compound' => $tax->compound,
                            'is_global' => $tax->is_global,
                        ],
                    ])
                );
            });
    }

    /**
     * Remove all tax conditions from the cart.
     *
     * @return void
     */
    public function removeTaxes(): void
    {
        $this->removeConditionsByType('tax');
        $this->flushComputedCache();
    }

    /**
     * Find applicable taxes for all cart items.
     *
     * @return Collection<Tax>
     */
    private function findTaxes(): Collection
    {
        $branch = $this->branch();

        return Tax::query()
            ->withOutGlobalBranchPermission()
            ->where('branch_id', $branch?->id())
            ->global()
            ->get()
            ->filter(fn(Tax $tax) => $this->isTaxApplicable($tax))
            ->values();
    }

    /**
     * Check if a given tax should apply based on type/order type/status.
     *
     * @param Tax $tax
     * @return bool
     */
    public function isTaxApplicable(Tax $tax): bool
    {
        $orderType = $this->orderType();

        if (empty($tax->order_types)) {
            return true;
        }

        if (is_null($orderType->value())) {
            return false;
        }

        return in_array($orderType->value(), $tax->order_types);
    }

    /**
     * Determine if discount already applied
     *
     * @param Discount|Voucher $discount
     * @return bool
     */
    public function discountAlreadyApplied(Discount|Voucher $discount): bool
    {
        $cartDiscount = $this->discount();

        return $cartDiscount->id() === $discount->id && $cartDiscount->model()->getMorphClass() == $discount->getMorphClass();
    }

    /**
     * @throws InvalidConditionException
     */
    public function applyDiscount(Discount|Voucher $discount, ?LoyaltyGift $gift = null): void
    {
        $this->removeDiscount();

        $morphClass = $discount->getMorphClass();
        $attributes = [
            'discount_id' => $discount->id,
            "model" => $morphClass,
        ];

        if (!is_null($gift)) {
            $attributes['loyalty_gift'] = [
                'id' => $gift->id,
            ];
        }

        $this->condition(
            new CartCondition([
                'name' => $morphClass == Voucher::class ? $discount->code : $discount->name,
                'type' => 'discount',
                'target' => 'total',
                'value' => $this->getDiscountValue($discount),
                'order' => 2,
                'attributes' => $attributes,
            ]),
        );

        $this->flushComputedCache();
    }

    /**
     * Get discount
     *
     * @param Discount|Voucher $discount
     * @return string
     */
    private function getDiscountValue(Discount|Voucher $discount): string
    {
        if ($discount->type->isPercent()) {
            return "-$discount->value%";
        }

        return "-{$discount->value->convert($this->branch()->currency())->amount()}";
    }


    /**
     * @throws InvalidConditionException
     */
    public function addCustomer(User $customer): CartCustomer
    {
        $this->removeCustomer();

        $this->condition(
            new CartCondition([
                'name' => "$customer->name ($customer->phone)",
                'type' => 'customer',
                'target' => 'total',
                'value' => 0,
                'attributes' => [
                    'customer' => $customer,
                ],
            ]),
        );

        return $this->customer();
    }

    public function removeCustomer(): void
    {
        $this->removeConditionsByType('customer');
        $this->flushComputedCache();
    }

    /**
     * @throws InvalidConditionException
     */
    public function addCurrentOrder(Order $order): CartCurrentOrder
    {
        $this->removeCurrentOrder();

        $this->condition(
            new CartCondition([
                'name' => $order->reference_no,
                'type' => 'current_order',
                'value' => $order->id,
            ]),
        );

        return $this->currentOrder();
    }

    public function removeCurrentOrder(): void
    {
        $this->removeConditionsByType('current_order');
        $this->flushComputedCache();
    }

    public function currentOrder(): ?CartCurrentOrder
    {
        return $this->hasCurrentOrder()
            ? new CartCurrentOrder($this->getConditionsByType('current_order')->first())
            : null;
    }

    public function hasCurrentOrder(): bool
    {
        return $this->getConditionsByType('current_order')->isNotEmpty();
    }
}
