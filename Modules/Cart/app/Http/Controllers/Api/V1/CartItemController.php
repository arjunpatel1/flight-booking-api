<?php

namespace Modules\Cart\Http\Controllers\Api\V1;

use Darryldecode\Cart\Exceptions\InvalidItemException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Cart\Facades\Cart;
use Modules\Cart\Http\Requests\Api\V1\StoreCartItemActionRequest;
use Modules\Cart\Http\Requests\Api\V1\StoreCartItemRequest;
use Modules\Cart\Traits\ValidatesCartItemOptions;
use Modules\Core\Http\Controllers\Controller;
use Modules\Category\Models\Category;
use Modules\Menu\Models\Menu;
use Modules\Product\Models\Product;
use Modules\Support\ApiResponse;

class CartItemController extends Controller
{
    use ValidatesCartItemOptions;

    /**
     * Store a newly created resource in storage.
     *
     * @throws InvalidItemException
     */
    public function store(StoreCartItemRequest $request): JsonResponse
    {
        Cart::store(
            $request->product_id,
            $request->qty,
            $request->options ?? [],
            tableId: $request->integer('table_id') ?: null,
            zoneId: $request->integer('zone_id') ?: null,
            loadedProduct: $request->product(),
            seatNumber: $request->integer('seat_number') ?: null,
            courseNumber: $request->integer('course_number') ?: null,
        );

        return ApiResponse::success(Cart::instance());
    }

    /**
     * Batch store multiple cart items in one request.
     */
    public function batchStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'max:100'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'min:1', 'max:999'],
            'items.*.options' => ['nullable', 'array'],
            'items.*.seat_number' => ['nullable', 'integer', 'min:1', 'max:99'],
            'items.*.course_number' => ['nullable', 'integer', 'min:1', 'max:20'],
            'table_id' => ['nullable', 'integer'],
            'zone_id' => ['nullable', 'integer'],
        ]);

        $productIds = collect($data['items'])->pluck('product_id')->unique()->values();

        $products = Product::query()
            ->with(['options.values', 'files', 'categories', 'taxes', 'menu', 'branch'])
            ->whereIn('id', $productIds)
            ->where('is_active', true)
            ->whereHas('menu', fn ($query) => $query
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->where('branch_id', auth()->user()->effective_branch->id))
            ->get()
            ->keyBy('id');

        if ($products->count() !== $productIds->count()) {
            throw ValidationException::withMessages([
                'items' => __('cart::validation.the_selected_product_is_invalid'),
            ]);
        }

        foreach ($data['items'] as $item) {
            $this->validateCartItemOptions($products->get($item['product_id']), $item['options'] ?? []);

            Cart::store(
                $item['product_id'],
                $item['qty'],
                $item['options'] ?? [],
                tableId: $request->integer('table_id') ?: null,
                zoneId: $request->integer('zone_id') ?: null,
                loadedProduct: $products->get($item['product_id']),
                seatNumber: isset($item['seat_number']) ? (int) $item['seat_number'] : null,
                courseNumber: isset($item['course_number']) ? (int) $item['course_number'] : null,
            );
        }

        return ApiResponse::success(Cart::instance());
    }

    /**
     * Store a quick/manual POS item as a hidden product so order totals,
     * taxes, kitchen routing, printing, and reports keep using the normal flow.
     */
    public function quickStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:99999999999999'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:99'],
            'seat_number' => ['nullable', 'integer', 'min:1', 'max:99'],
            'menu_id' => ['required', 'integer'],
            'table_id' => ['nullable', 'integer'],
            'zone_id' => ['nullable', 'integer'],
        ]);

        $branch = auth()->user()->effective_branch;
        $menu = Menu::query()
            ->whereKey($data['menu_id'])
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->firstOrFail();

        $product = Product::query()->create([
            'name' => ['en' => $data['name'], 'ar' => $data['name']],
            'description' => null,
            'menu_id' => $menu->id,
            'price' => $data['price'],
            'sku' => 'QUICK-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6)),
            'is_available' => true,
            'is_recommended' => false,
            'is_best_seller' => false,
            'display_priority' => 0,
            'is_active' => true,
            'notes' => 'POS quick item',
        ]);

        // Menu/catalog reads are category-driven. Persist quick items under a
        // stable menu category so they do not disappear after the POS cart is
        // closed.
        $quickItemsCategory = Category::query()
            ->withoutGlobalActive()
            ->firstOrCreate(
                ['menu_id' => $menu->id, 'slug' => 'quick-items'],
                [
                    'name' => ['en' => 'Quick Items', 'ar' => 'عناصر سريعة'],
                    'is_active' => true,
                ],
            );
        $product->categories()->syncWithoutDetaching([$quickItemsCategory->id]);

        $product->load(['options.values', 'files', 'categories', 'taxes', 'menu', 'branch']);

        Cart::store(
            $product->id,
            $data['qty'] ?? 1,
            [],
            null,
            null,
            $request->integer('table_id') ?: null,
            $request->integer('zone_id') ?: null,
            $product,
            true,
            $data['seat_number'] ?? null,
        );

        return ApiResponse::success(Cart::instance());
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $cartId, string $id): JsonResponse
    {
        $data = $request->validate([
            'qty' => ['nullable', 'integer', 'min:1', 'max:999'],
            'seat_number' => ['nullable', 'integer', 'min:1', 'max:99'],
            'course_number' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        if (! isset(Cart::get($id)['attributes']['loyalty_gift']['id'])) {
            if (array_key_exists('qty', $data)) {
                Cart::updateQuantity($id, $data['qty'] ?: 1);
            }

            if (array_key_exists('seat_number', $data)) {
                Cart::updateSeatNumber($id, isset($data['seat_number']) ? (int) $data['seat_number'] : null);
            }

            if (array_key_exists('course_number', $data)) {
                Cart::updateCourseNumber($id, isset($data['course_number']) ? (int) $data['course_number'] : null);
            }
        }

        return ApiResponse::success(Cart::instance());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $cartId, string $id): JsonResponse
    {
        Cart::remove($id);

        return ApiResponse::success(Cart::instance());
    }

    /**
     * Store action
     */
    public function storeAction(StoreCartItemActionRequest $request, string $cartId, string $id): JsonResponse
    {
        if (! isset(Cart::get($id)['attributes']['loyalty_gift']['id'])) {
            Cart::storeAction($id, $request->action, $request->qty);
        }

        return ApiResponse::success(Cart::instance());
    }

    /**
     * Remove action
     */
    public function destroyAction(Request $request, string $cartId, string $id): JsonResponse
    {
        Cart::deleteAction($id, $request->action);

        return ApiResponse::success(Cart::instance());
    }
}
