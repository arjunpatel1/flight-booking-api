<?php

namespace Modules\Cart\Http\Controllers\Api\V1;

use Darryldecode\Cart\Exceptions\InvalidConditionException;
use Illuminate\Http\JsonResponse;
use Modules\Cart\Facades\Cart;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class CartCustomerController extends Controller
{
    /**
     * Add Customer to cart
     *
     * @param string $cartId
     * @param int $id
     * @return JsonResponse
     * @throws InvalidConditionException
     */
    public function store(string $cartId, int $id): JsonResponse
    {
        Cart::addCustomer(User::role(DefaultRole::Customer)->findOrFail($id));

        return ApiResponse::success(Cart::instance());
    }

    /**
     * Remove customer from cart
     *
     * @return JsonResponse
     */
    public function destroy(): JsonResponse
    {
        Cart::removeCustomer();

        return ApiResponse::success(Cart::instance());
    }
}
