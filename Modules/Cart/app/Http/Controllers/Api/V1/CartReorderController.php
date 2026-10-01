<?php

namespace Modules\Cart\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Cart\Facades\Cart;
use Modules\Core\Http\Controllers\Controller;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderProduct;
use Modules\Order\Models\OrderProductOption;
use Modules\Order\Transformers\Api\V1\OrderResource;
use Modules\Order\Transformers\Api\V1\ShowOrderResource;
use Modules\Product\Models\Product;
use Modules\Support\ApiResponse;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class CartReorderController extends Controller
{
    /**
     * List recent repeatable orders for a customer in the active branch.
     */
    public function recent(Request $request, string $cartId, int $customerId): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->baseOrderQuery($request, $customerId)
                ->with(['branch:id,name', 'table:id,name'])
                ->latest()
                ->paginate(min(max($request->integer('per_page', 10), 1), 25)),
            resource: OrderResource::class
        );
    }

    /**
     * Show the latest repeatable order for a customer.
     */
    public function last(Request $request, string $cartId, int $customerId): JsonResponse
    {
        return ApiResponse::success(
            body: new ShowOrderResource($this->lastRepeatableOrder($request, $customerId))
        );
    }

    /**
     * Repeat the customer's latest order into the current cart.
     */
    public function repeatLast(Request $request, string $cartId, int $customerId): JsonResponse
    {
        return $this->repeatOrder(
            request: $request,
            customerId: $customerId,
            order: $this->lastRepeatableOrder($request, $customerId)
        );
    }

    /**
     * Repeat a specific historical order into the current cart.
     */
    public function repeat(Request $request, string $cartId, int $customerId, int|string $orderId): JsonResponse
    {
        return $this->repeatOrder(
            request: $request,
            customerId: $customerId,
            order: $this->baseOrderQuery($request, $customerId)->whereKey($orderId)->firstOrFail()
        );
    }

    /**
     * Repeat a selected order for the authenticated operator. Unlike the
     * customer reorder route this also supports walk-in orders, while the
     * active tenant/branch scopes still prevent foreign-order access.
     */
    public function repeatSelected(Request $request, string $cartId, int|string $orderId): JsonResponse
    {
        $order = $this->repeatableOrdersForActiveBranch($request)
            ->whereKey($orderId)
            ->firstOrFail();

        return $this->repeatOrder(
            request: $request,
            customerId: $order->customer_id,
            order: $order
        );
    }

    private function repeatOrder(Request $request, ?int $customerId, Order $order): JsonResponse
    {
        $data = $request->validate([
            'clear_existing' => ['nullable', 'boolean'],
            'table_id' => ['nullable', 'integer'],
            'zone_id' => ['nullable', 'integer'],
        ]);

        $customer = $customerId ? $this->customer($customerId) : null;
        $branch = Branch::withoutGlobalActive()->findOrFail($order->branch_id);
        $tableId = $request->integer('table_id') ?: null;
        $zoneId = $request->integer('zone_id') ?: null;

        if ((bool) ($data['clear_existing'] ?? false)) {
            Cart::clear();
        }

        Cart::addBranch($branch);
        Cart::addOrderType($order->type);
        if ($customer) {
            Cart::addCustomer($customer);
        }

        $skipped = [];
        $addedCount = 0;

        foreach ($order->products as $orderProduct) {
            $product = $this->activeBranchProduct($orderProduct, $branch->id);

            if (! $product) {
                $skipped[] = $this->skippedItem($orderProduct, 'product_unavailable');
                continue;
            }

            $options = $this->selectedOptionsPayload($orderProduct);
            Cart::store(
                productId: $product->id,
                qty: min(max((int) $orderProduct->quantity, 1), 999),
                options: $options,
                tableId: $tableId,
                zoneId: $zoneId,
                loadedProduct: $product
            );
            $addedCount++;
        }

        if ($addedCount === 0) {
            throw ValidationException::withMessages([
                'order_id' => __('cart::validation.the_selected_product_is_invalid'),
            ]);
        }

        return ApiResponse::success(body: [
            'source_order' => new ShowOrderResource($order),
            'cart' => Cart::instance(),
            'added_items_count' => $addedCount,
            'skipped_items' => $skipped,
        ]);
    }

    private function lastRepeatableOrder(Request $request, int $customerId): Order
    {
        return $this->baseOrderQuery($request, $customerId)
            ->latest()
            ->firstOrFail();
    }

    private function baseOrderQuery(Request $request, int $customerId)
    {
        return $this->repeatableOrdersForActiveBranch($request)
            ->where('customer_id', $this->customer($customerId)->id);
    }

    private function repeatableOrdersForActiveBranch(Request $request)
    {
        // A branch-scoped identity must never widen its scope by supplying a
        // different branch_id. Tenant-wide identities may explicitly select
        // the branch whose historical order they are repeating.
        $effectiveBranchId = auth()->user()?->effective_branch?->id;
        $branchId = $effectiveBranchId ?: ($request->integer('branch_id') ?: null);

        return Order::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereNotIn('status', [
                OrderStatus::Cancelled,
                OrderStatus::Refunded,
                OrderStatus::Merged,
            ])
            ->whereHas('products', fn ($query) => $query->whereNotIn('status', [
                OrderProductStatus::Cancelled,
                OrderProductStatus::Refunded,
            ]))
            ->with([
                'branch:id,name',
                'customer:id,name,phone',
                'table:id,name',
                'products' => fn ($query) => $query->whereNotIn('status', [
                    OrderProductStatus::Cancelled,
                    OrderProductStatus::Refunded,
                ]),
                'products.product',
                'products.options.option',
                'products.options.values',
                'products.taxes',
            ]);
    }

    private function customer(int $customerId): User
    {
        return User::role(DefaultRole::Customer)->findOrFail($customerId);
    }

    private function activeBranchProduct(OrderProduct $orderProduct, int $branchId): ?Product
    {
        return Product::query()
            ->with(['options.values', 'files', 'categories', 'taxes', 'menu', 'branch'])
            ->whereKey($orderProduct->product_id)
            ->where('is_active', true)
            ->whereHas('menu', fn ($query) => $query
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->where('branch_id', $branchId))
            ->first();
    }

    private function selectedOptionsPayload(OrderProduct $orderProduct): array
    {
        return $orderProduct->options
            ->mapWithKeys(function (OrderProductOption $option) {
                if ($option->option?->type?->isFieldType()) {
                    return [$option->option_id => $option->value ?? $option->values->first()?->label];
                }

                return [$option->option_id => $option->values->pluck('id')->all()];
            })
            ->filter(fn ($value) => filled($value))
            ->all();
    }

    private function skippedItem(OrderProduct $orderProduct, string $reason): array
    {
        return [
            'order_product_id' => $orderProduct->id,
            'product_id' => $orderProduct->product_id,
            'product_name' => $orderProduct->name,
            'quantity' => $orderProduct->quantity,
            'reason' => $reason,
        ];
    }
}
