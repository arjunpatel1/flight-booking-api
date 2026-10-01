<?php

namespace Modules\Cart\Http\Controllers\Api\V1;

use Darryldecode\Cart\Exceptions\InvalidItemException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Support\InputLimit;
use Modules\Cart\Facades\Cart;
use Modules\Cart\Http\Requests\Api\V1\Public\StoreCartItemRequest;
use Modules\Cart\Support\PublicTenantGuard;
use Modules\Cart\Traits\ValidatesCartItemOptions;
use Modules\Core\Http\Controllers\Controller;
use Modules\Order\Enums\OrderType;
use Modules\Product\Models\Product;
use Modules\Support\ApiResponse;

class PublicCartItemController extends Controller
{
    use ValidatesCartItemOptions;

    /**
     * Store a newly created resource in storage
     *
     * @throws InvalidItemException
     */
    public function store(StoreCartItemRequest $request, string $cartId): JsonResponse
    {
        $branch = PublicTenantGuard::branch($request, $request->input('branch_id'));
        $this->syncPricingContext($request, $branch);
        $product = Product::query()->with(['options.values', 'files', 'categories', 'taxes', 'menu', 'branch'])
            ->whereKey($request->integer('product_id'))
            ->where('is_active', true)
            ->whereHas('menu', fn ($query) => $query
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->where('branch_id', $branch->id))
            ->firstOrFail();
        $options = $this->normalizeCartItemOptionReferences($product, $request->input('options', []));
        $this->validateCartItemOptions($product, $options);

        Cart::store(
            $product->id,
            $request->qty,
            $options,
            tableId: $request->integer('table_id') ?: null,
            seatNumber: $request->integer('seat_number') ?: null,
            loadedProduct: $product,
        );

        return ApiResponse::success(Cart::instance());
    }

    /**
     * Batch store multiple cart items in one request.
     */
    public function batchStore(Request $request, string $cartId): JsonResponse
    {
        $data = $request->validate([
            'menu_reference' => ['required_without:branch_id', 'nullable', 'uuid'],
            'branch_id' => ['required_without:menu_reference', 'nullable', 'integer', 'exists:branches,id'],
            'order_type' => ['nullable', Rule::enum(OrderType::class)],
            'items' => ['required', 'array', 'max:100'],
            'items.*.product_reference' => ['required_without:items.*.product_id', 'nullable', 'uuid'],
            // Temporary compatibility for already deployed clients. New
            // customer clients use the opaque, menu-scoped reference.
            'items.*.product_id' => ['required_without:items.*.product_reference', 'nullable', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'min:1', 'max:999'],
            'items.*.options' => ['nullable', 'array'],
            'items.*.seat_number' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $menu = filled($data['menu_reference'] ?? null)
            ? PublicTenantGuard::menu($request, (string) $data['menu_reference'])
            : null;
        $branch = PublicTenantGuard::branch($request, $menu?->branch_id ?? $data['branch_id']);
        $menuId = $menu?->id;
        $this->syncPricingContext($request, $branch);

        $productIds = collect($data['items'])->pluck('product_id')->filter()->unique()->values();
        $productReferences = collect($data['items'])->pluck('product_reference')->filter()->unique()->values();

        $products = Product::query()
            ->with(['options.values', 'files', 'categories', 'taxes', 'menu', 'branch'])
            ->where(function ($query) use ($productIds, $productReferences): void {
                $query->when($productReferences->isNotEmpty(), fn ($query) => $query
                    ->whereIn('uuid', $productReferences));

                if ($productIds->isNotEmpty()) {
                    $method = $productReferences->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('id', $productIds);
                }
            })
            ->where('is_active', true)
            ->whereHas('menu', fn ($query) => $query
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->when($menuId, fn ($query) => $query->whereKey($menuId))
                ->where('branch_id', $branch->id))
            ->get();

        $productsById = $products->keyBy('id');
        $productsByReference = $products->keyBy('uuid');

        foreach ($data['items'] as $item) {
            $product = filled($item['product_reference'] ?? null)
                ? $productsByReference->get($item['product_reference'])
                : $productsById->get($item['product_id']);

            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => __('cart::validation.the_selected_product_is_invalid'),
                ]);
            }

            $options = $this->normalizeCartItemOptionReferences($product, $item['options'] ?? []);
            $this->validateCartItemOptions($product, $options);

            Cart::store(
                $product->id,
                $item['qty'],
                $options,
                tableId: $request->integer('table_id') ?: null,
                loadedProduct: $product,
                seatNumber: isset($item['seat_number']) ? (int) $item['seat_number'] : null,
            );
        }

        return ApiResponse::success(Cart::instance());
    }

    /**
     * Make each product mutation authoritative for its pricing context instead
     * of relying on an earlier asynchronous order-type request.
     */
    private function syncPricingContext(Request $request, Branch $branch): void
    {
        Cart::addBranch($branch);

        if (! $request->filled('order_type')) {
            return;
        }

        $orderType = OrderType::from((string) $request->input('order_type'));
        $allowedOrderTypes = collect($branch->order_types ?: [])
            ->map(fn ($type) => $type instanceof OrderType ? $type->value : (string) $type)
            ->all();

        if (! in_array($orderType->value, $allowedOrderTypes, true)) {
            throw ValidationException::withMessages([
                'order_type' => __('order::orders.order_type_not_allowed'),
            ]);
        }

        Cart::addOrderType($orderType);
    }

    /**
     * Update the specified resource in storage
     *
     * @throws ValidationException
     */
    public function update(Request $request, string $cartId, string $itemId): JsonResponse
    {
        PublicTenantGuard::assertBranch($request, Cart::branch()?->id());

        $request->validate([
            // Whole units only, and bounded. This is a public (unauthenticated)
            // cart endpoint, so an unbounded qty was reachable by anyone.
            'qty' => ['nullable', ...InputLimit::quantity(min: 1, integer: true)],
            'seat_number' => 'nullable|integer|min:1|max:99',
        ]);

        // Check if item exists and is not a loyalty gift
        $cartItem = Cart::get($itemId);
        if (! $cartItem) {
            return ApiResponse::errors(
                errors: ['item_id' => 'Item not found in cart'],
                message: 'Item not found',
                code: 404
            );
        }

        if (! isset($cartItem['attributes']['loyalty_gift']['id'])) {
            if ($request->exists('qty')) {
                Cart::updateQuantity($itemId, $request->integer('qty') ?: 1);
            }

            if ($request->exists('seat_number')) {
                Cart::updateSeatNumber($itemId, $request->integer('seat_number') ?: null);
            }
        }

        return ApiResponse::success(Cart::instance());
    }

    /**
     * Remove the specified resource from storage
     */
    public function destroy(string $cartId, string $itemId): JsonResponse
    {
        PublicTenantGuard::assertBranch(request(), Cart::branch()?->id());

        // Check if item exists
        $cartItem = Cart::get($itemId);
        if (! $cartItem) {
            return ApiResponse::errors(
                errors: ['item_id' => 'Item not found in cart'],
                message: 'Item not found',
                code: 404
            );
        }

        Cart::remove($itemId);

        return ApiResponse::success(Cart::instance());
    }

    /**
     * Store action for cart item
     *
     * @throws ValidationException
     */
    public function storeAction(Request $request, string $cartId, string $itemId): JsonResponse
    {
        PublicTenantGuard::assertBranch($request, Cart::branch()?->id());

        $request->validate([
            // Length-bounded rather than enumerated: the accepted action set is
            // owned by the cart service, and pinning it here would duplicate
            // business logic this audit must not change.
            'action' => ['required', 'string', 'max:60'],
            'qty' => ['nullable', ...InputLimit::quantity(min: 1, integer: true)],
        ]);

        // Check if item exists
        $cartItem = Cart::get($itemId);
        if (! $cartItem) {
            return ApiResponse::errors(
                errors: ['item_id' => 'Item not found in cart'],
                message: 'Item not found',
                code: 404
            );
        }

        if (! isset($cartItem['attributes']['loyalty_gift']['id'])) {
            Cart::storeAction($itemId, $request->action, $request->qty);
        }

        return ApiResponse::success(Cart::instance());
    }

    /**
     * Remove action from cart item
     *
     * @throws ValidationException
     */
    public function destroyAction(Request $request, string $cartId, string $itemId): JsonResponse
    {
        PublicTenantGuard::assertBranch($request, Cart::branch()?->id());

        $request->validate([
            'action' => ['required', 'string', 'max:60'],
        ]);

        // Check if item exists
        $cartItem = Cart::get($itemId);
        if (! $cartItem) {
            return ApiResponse::errors(
                errors: ['item_id' => 'Item not found in cart'],
                message: 'Item not found',
                code: 404
            );
        }

        Cart::deleteAction($itemId, $request->action);

        return ApiResponse::success(Cart::instance());
    }
}
