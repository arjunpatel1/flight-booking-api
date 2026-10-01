<?php

namespace Modules\Cart\Http\Controllers\Api\V1;

use Darryldecode\Cart\Exceptions\InvalidConditionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cart\Facades\Cart;
use Modules\Cart\Support\PublicTenantGuard;
use Modules\Core\Http\Controllers\Controller;
use Modules\Order\Enums\OrderType;
use Modules\Support\ApiResponse;

class PublicCartController extends Controller
{
    /**
     * Get a new instance of cart
     *
     * @param string $cartId
     * @param Request $request
     * @return JsonResponse
     */
    public function index(string $cartId, Request $request): JsonResponse
    {
        $this->initializeCartContext($request);
        
        return ApiResponse::success(Cart::instance());
    }

    /**
     * Get cart meta
     *
     * @param string $cartId
     * @return JsonResponse
     */
    public function getMeta(string $cartId): JsonResponse
    {
        return ApiResponse::success([]);
    }

    /**
     * Initialize a new cart
     *
     * @param string $cartId
     * @param Request $request
     * @return JsonResponse
     * @throws InvalidConditionException
     */
    public function initialize(string $cartId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'menu_reference' => ['required_without:branch_id', 'nullable', 'uuid'],
            'branch_id' => ['required_without:menu_reference', 'nullable', 'integer'],
            'order_type' => ['nullable', \Illuminate\Validation\Rule::enum(OrderType::class)],
        ]);

        if (filled($data['menu_reference'] ?? null)) {
            $menu = PublicTenantGuard::menu($request, (string) $data['menu_reference']);
            $branch = PublicTenantGuard::branch($request, $menu->branch_id);
        } elseif (filled($data['branch_id'] ?? null)) {
            $branch = PublicTenantGuard::branch($request, $data['branch_id']);
        } else {
            return ApiResponse::errors(
                errors: ['menu_reference' => 'Menu reference is required'],
                message: 'Menu reference is required',
                code: 422
            );
        }

        Cart::clear();
        Cart::addBranch($branch);

        if ($request->input('order_type')) {
            $orderType = OrderType::tryFrom($request->input('order_type'));
            if ($orderType) {
                Cart::addOrderType($orderType);
            }
        }

        return ApiResponse::success(Cart::instance());
    }

    /**
     * Clear cart
     *
     * @param string $cartId
     * @param Request $request
     * @return JsonResponse
     * @throws InvalidConditionException
     */
    public function clear(string $cartId, Request $request): JsonResponse
    {
        $this->initializeCartContext($request);
        
        // Preserve cart context before clearing
        $orderType = null;
        $branch = null;
        
        try {
            $orderType = Cart::orderType()?->value();
            $branchId = Cart::branch()?->id();
            $branch = $branchId ? PublicTenantGuard::branch($request, $branchId) : null;
        } catch (\Exception $e) {
            // Cart might be empty or not initialized
        }

        Cart::clear();

        // Restore context if available
        if ($orderType) {
            Cart::addOrderType(OrderType::from($orderType));
        }

        if ($branch) {
            Cart::addBranch($branch);
        }

        return ApiResponse::success(Cart::instance());
    }

    /**
     * Initialize cart context for public requests
     *
     * @param Request $request
     * @return void
     */
    private function initializeCartContext(Request $request): void
    {
        // Cart context is maintained via cartId in the URL
        // Branch context is set during initialization or retrieved from cart data
        // No authentication required for public access
        PublicTenantGuard::assertBranch($request, Cart::branch()?->id());
    }
}
