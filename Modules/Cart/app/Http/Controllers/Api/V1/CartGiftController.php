<?php

namespace Modules\Cart\Http\Controllers\Api\V1;

use Darryldecode\Cart\Exceptions\InvalidItemException;
use Illuminate\Http\JsonResponse;
use Modules\Cart\Facades\Cart;
use Modules\Cart\Services\DiscountApplyService\DiscountApplyServiceInterface;
use Modules\Core\Http\Controllers\Controller;
use Modules\Loyalty\Enums\LoyaltyRewardType;
use Modules\Loyalty\Services\LoyaltyGift\LoyaltyGiftServiceInterface;
use Modules\Product\Models\Product;
use Modules\Support\ApiResponse;

class CartGiftController extends Controller
{
    /**
     * Apply discount to cart
     *
     * @param LoyaltyGiftServiceInterface $giftService
     * @param DiscountApplyServiceInterface $applyService
     * @param string $cartId
     * @param int $id
     * @return JsonResponse
     * @throws InvalidItemException
     */
    public function store(
        LoyaltyGiftServiceInterface   $giftService,
        DiscountApplyServiceInterface $applyService,
        string                        $cartId,
        int                           $id
    ): JsonResponse
    {

        $customer = Cart::customer();

        $gift = $customer ? $giftService->availableGift($customer->id(), $id) : null;

        abort_if(is_null($gift), 400, __("loyalty::messages.gift_unavailable"));

        switch ($gift->type) {
            case LoyaltyRewardType::Discount:
                $applyService->applyDiscount(Cart::class, $gift->artifact_id, $gift);
                break;
            case LoyaltyRewardType::VoucherCode:
                $applyService->applyVoucher(Cart::class, $gift->artifact->code, $gift);
                break;
            case LoyaltyRewardType::FreeItem:
                $product = Product::query()
                    ->whereHas('menu',
                        fn($query) => $query
                            ->whereNull("deleted_at")
                            ->where('is_active', true)
                            ->where('branch_id', auth()->user()->effective_branch->id)
                    )
                    ->select('id', 'is_active', 'menu_id')
                    ->where('sku', $gift->meta['product_sku'] ?? null)
                    ->first();
                abort_if(is_null($product), 400, __("cart::messages.gift_cannot_added_to_this_cart"));
                Cart::store(
                    productId: $product->id,
                    qty: $gift->meta['quantity'] ?? 1,
                    gift: $gift
                );
                break;
            default:
        }

        return ApiResponse::success(Cart::instance());
    }
}
