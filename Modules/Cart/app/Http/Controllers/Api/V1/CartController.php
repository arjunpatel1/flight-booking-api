<?php

namespace Modules\Cart\Http\Controllers\Api\V1;

use Darryldecode\Cart\Exceptions\InvalidConditionException;
use Illuminate\Http\JsonResponse;
use Modules\Branch\Models\Branch;
use Modules\Cart\Facades\Cart;
use Modules\Core\Http\Controllers\Controller;
use Modules\Order\Enums\OrderType;
use Modules\Support\ApiResponse;

class CartController extends Controller
{
    /**
     * Get a new instance of cart
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success(Cart::instance());
    }

    /**
     * Get cart meta
     *
     * @return JsonResponse
     */
    public function getMeta(): JsonResponse
    {
        return ApiResponse::success([]);
    }

    /**
     * Clear the cart.
     *
     * @return JsonResponse
     * @throws InvalidConditionException
     */
    public function clear(): JsonResponse
    {
        $orderType = Cart::orderType()->value();
        $branch = Branch::withoutGlobalActive()->find(Cart::branch()->id());

        Cart::clear();

        if ($orderType) {
            Cart::addOrderType(OrderType::from($orderType));
        }

        if ($branch) {
            Cart::addBranch($branch);
        }

        return ApiResponse::success(Cart::instance());
    }
}
