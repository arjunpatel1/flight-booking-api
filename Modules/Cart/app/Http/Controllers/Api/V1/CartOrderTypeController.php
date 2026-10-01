<?php

namespace Modules\Cart\Http\Controllers\Api\V1;

use Darryldecode\Cart\Exceptions\InvalidConditionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Branch\Models\Branch;
use Modules\Cart\Facades\Cart;
use Modules\Core\Http\Controllers\Controller;
use Modules\Order\Enums\OrderType;
use Modules\Support\ApiResponse;
use Modules\User\Enums\DefaultRole;

class CartOrderTypeController extends Controller
{
    /**
     * Store a newly created resource in storage.
     *
     * @param string $cartId
     * @param OrderType $type
     * @return JsonResponse
     * @throws InvalidConditionException
     */
    public function store(Request $request, string $cartId, OrderType $type): JsonResponse
    {
        $branch = $this->resolveAllowedBranch($request, $type);

        // A fresh post-checkout cart may have no branch condition yet. Adding
        // an order type without it makes branch_id NULL eligible for platform
        // taxes, which then leak into a restaurant cart until it is rebuilt.
        if ($branch) {
            Cart::addBranch($branch);
        }

        Cart::addOrderType($type);
        Cart::repriceItems(
            tableId: $request->integer('table_id') ?: null,
            zoneId: $request->integer('zone_id') ?: null,
        );

        return ApiResponse::success(Cart::instance());
    }

    /**
     * Destroy resource's.
     *
     * @return JsonResponse
     */
    public function destroy(): JsonResponse
    {
        Cart::removeOrderType();

        return ApiResponse::success(Cart::instance());
    }

    private function resolveAllowedBranch(Request $request, OrderType $type): ?Branch
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        $branchId = $request->integer('branch_id')
            ?: Cart::branch()?->id()
            ?: $user->branch_id
            ?: $user->effective_branch?->id;
        $branch = null;
        if ($branchId) {
            $branch = Branch::withoutGlobalActive()->findOrFail($branchId);
            $branchOrderTypes = $branch->order_types ?: [];

            abort_if(
                !empty($branchOrderTypes) && !in_array($type->value, $branchOrderTypes, true),
                422,
                __('order::orders.order_type_not_allowed')
            );
        }

        $assignedOrderTypes = $user->hasRole(DefaultRole::Waiter->value)
            ? array_values(array_filter($user->order_types ?: []))
            : [];

        abort_if(
            !empty($assignedOrderTypes) && !in_array($type->value, $assignedOrderTypes, true),
            422,
            __('order::orders.order_type_not_allowed')
        );

        return $branch;
    }
}
