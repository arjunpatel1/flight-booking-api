<?php

namespace Modules\Cart\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Modules\Cart\Facades\Cart;
use Modules\Cart\Services\DiscountApplyService\DiscountApplyServiceInterface;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;

class CartDiscountController extends Controller
{

    /**
     * Apply discount to cart
     *
     * @param DiscountApplyServiceInterface $service
     * @param string $cartId
     * @param int $id
     * @return JsonResponse
     */
    public function store(DiscountApplyServiceInterface $service, string $cartId, int $id): JsonResponse
    {
        $service->applyDiscount(Cart::class, $id);

        return ApiResponse::success(Cart::instance());
    }


    /**
     * Remove discount from cart
     *
     * @return JsonResponse
     */
    public function destroy(): JsonResponse
    {
        Cart::removeDiscount();

        return ApiResponse::success(Cart::instance());
    }
}
