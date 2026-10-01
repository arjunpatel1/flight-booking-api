<?php

namespace Modules\Cart\Facades;

use Darryldecode\Cart\CartCollection;
use Illuminate\Support\Facades\Facade;
use Modules\Branch\Models\Branch;
use Modules\Cart\CartBranch;
use Modules\Cart\CartCustomer;
use Modules\Cart\CartOrderType;
use Modules\Discount\Models\Discount;
use Modules\Loyalty\Models\LoyaltyGift;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderProduct;
use Modules\Product\Models\Product;
use Modules\User\Models\User;

/**
 * @method static \Modules\Cart\Cart instance()
 * @method static void clear()
 * @method static void store(int $productId, int $qty, array $options = [], ?OrderProduct $orderProduct = null, ?LoyaltyGift $gift = null, ?int $tableId = null, ?int $zoneId = null, ?Product $loadedProduct = null, bool $quickItem = false)
 * @method static void updateQuantity(string $id, int $qty)
 * @method static CartOrderType addOrderType(OrderType $type)
 * @method static void removeOrderType()
 * @method static CartCollection items()
 * @method static int addedQty(int $productId)
 * @method static bool remove(string $id)
 * @method static void applyDiscount(Discount $discount, ?LoyaltyGift $gift = null)
 * @method static void removeDiscount()
 * @method static CartCustomer addCustomer(User $customer)
 * @method static CartBranch addBranch(Branch $branch)
 * @method static void removeCustomer()
 * @method static void initOrder(Order $order)
 *
 * @see \Modules\Cart\Cart
 */
class Cart extends Facade
{
    protected static $cached = false;

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return \Modules\Cart\Cart::class;
    }
}
