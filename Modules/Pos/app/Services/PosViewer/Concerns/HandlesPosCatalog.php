<?php

namespace Modules\Pos\Services\PosViewer\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Modules\Category\Models\Category;
use Modules\Order\Enums\OrderStatus;
use Modules\Pos\Transformers\Api\V1\Pos\PosCategoryResource;
use Modules\Pos\Transformers\Api\V1\Pos\PosProductResource;
use Modules\Product\Models\Product;

trait HandlesPosCatalog
{
    /** {@inheritDoc} */
    public function getCategories(int $menuId): array
    {
        return $this->cacheWithTags(['categories'])
            ->rememberForever(
                makeCacheKey([
                    'categories',
                    "menu-$menuId",
                    'pos_tree',
                ]),
                fn () => PosCategoryResource::collection(
                    Category::query()
                        ->select(['id', 'name', 'parent_id', 'order', 'menu_id'])
                        ->with(['childrenRecursive', 'files'])
                        ->whereNull('parent_id')
                        ->whereMenu($menuId)
                        ->withOutGlobalBranchPermission()
                        ->orderBy('order')
                        ->get()
                )->resolve(request())
            );
    }

    /** {@inheritDoc} */
    public function getProducts(
        int $menuId,
        ?int $priceTypeId = null,
        ?string $orderType = null,
        ?int $tableId = null,
        bool $includeOptions = false,
    ): array {
        $bestSellerWindowDays = max((int) setting('pos_best_seller_window_days', 30), 1);
        $bestSellerLimit = max((int) setting('pos_best_seller_limit', 10), 1);
        $cacheMinutes = max((int) setting('pos_menu_cache_minutes', 5), 1);
        $useSalesBestSellerQuery = filter_var(setting('pos_sales_best_seller_from_orders', false), FILTER_VALIDATE_BOOLEAN);
        $waiterId = auth()->id();
        $shiftName = $this->currentMemoryShift();
        $normalizedOrderType = $this->normalizedMemoryOrderType($orderType);
        $productsUpdatedAt = $this->supportsCacheTags()
            ? null
            : (Product::query()->whereMenu($menuId)->max('updated_at') ?: 'empty');

        $resolver = fn () => $this->buildProductsPayload(
            menuId: $menuId,
            bestSellerWindowDays: $bestSellerWindowDays,
            bestSellerLimit: $bestSellerLimit,
            priceTypeId: $priceTypeId,
            useSalesBestSellerQuery: $useSalesBestSellerQuery,
            waiterId: $waiterId,
            tableId: $tableId,
            orderType: $normalizedOrderType,
            shiftName: $shiftName,
            includeOptions: $includeOptions,
        );

        if (! $this->shouldCachePosProductList()) {
            return $resolver();
        }

        return $this->cacheWithTags(['products', 'categories'])
            ->remember(
                makeCacheKey(array_filter([
                    'products',
                    "menu-$menuId",
                    'pos',
                    'pricing-policy-v5',
                    $includeOptions ? 'with-options' : 'lean-list',
                    'price-type-'.($priceTypeId ?: 'base'),
                    'sales-best-seller-'.($useSalesBestSellerQuery ? 'on' : 'off'),
                    "sales-window-$bestSellerWindowDays",
                    "best-seller-limit-$bestSellerLimit",
                    'memory-v1',
                    "shift-$shiftName",
                    'waiter-'.($waiterId ?: 0),
                    'table-'.($tableId ?: 0),
                    'order-type-'.($normalizedOrderType ?: 'any'),
                    $productsUpdatedAt ? "updated-$productsUpdatedAt" : null,
                ])),
                now()->addMinutes($cacheMinutes),
                $resolver
            );
    }

    private function buildProductsPayload(
        int $menuId,
        int $bestSellerWindowDays,
        int $bestSellerLimit,
        ?int $priceTypeId,
        bool $useSalesBestSellerQuery,
        ?int $waiterId,
        ?int $tableId,
        ?string $orderType,
        string $shiftName,
        bool $includeOptions,
    ): array {
        $products = Product::query()
            ->select([
                'id',
                'uuid',
                'sku',
                'name',
                'description',
                'price',
                'special_price',
                'special_price_type',
                'special_price_start',
                'special_price_end',
                'new_from',
                'new_to',
                'is_available',
                'is_recommended',
                'is_best_seller',
                'display_priority',
                'notes',
                'allergens',
                'dietary_labels',
                'food_type',
                'hsn_code',
                'menu_id',
                'image_thumbnail_path',
                'created_at',
                'updated_at',
            ])
            ->with([
                'categories:id,menu_id,name',
                'files' => fn ($query) => $query->wherePivot('zone', 'thumbnail'),
            ])
            // Public menus need complete choice groups. POS list screens keep
            // the lean payload and fetch customisation data on demand.
            ->when($includeOptions, fn (Builder $query) => $query->with([
                'options' => fn ($query) => $query->with('values'),
            ]))
            ->withExists('options')
            ->withSum([
                'orderProducts as sales_quantity' => fn (Builder $query) => $query
                    ->whereHas('order', fn (Builder $query) => $query
                        ->whereIn('status', [OrderStatus::Served->value, OrderStatus::Completed->value])
                        ->where('created_at', '>=', now()->subDays($bestSellerWindowDays))),
            ], 'quantity')
            ->whereHas(
                'categories',
                fn ($query) => $query->whereIn('id', Category::getFullyActiveCategoryIds($menuId))
            )
            ->whereMenu($menuId)
            ->orderByDesc('display_priority')
            ->orderByDesc('sales_quantity')
            ->orderByDesc('is_best_seller')
            ->orderByDesc('is_recommended')
            ->latest()
            ->get();

        $this->priceResolver->applyToProducts($products, $priceTypeId);

        $salesBestSellerIds = $useSalesBestSellerQuery
            ? $products
                ->filter(fn (Product $product) => (int) ($product->sales_quantity ?? 0) > 0)
                ->sortByDesc(fn (Product $product) => (int) ($product->sales_quantity ?? 0))
                ->take($bestSellerLimit)
                ->pluck('id')
                ->all()
            : [];

        $products->each(fn (Product $product) => $product->setAttribute(
            'is_sales_best_seller',
            in_array($product->id, $salesBestSellerIds, true)
        ));
        $this->attachRestaurantMemory(
            products: $products,
            menuId: $menuId,
            waiterId: $waiterId,
            tableId: $tableId,
            orderType: $orderType,
            shiftName: $shiftName,
        );

        return PosProductResource::collection($products)->resolve(request());
    }
}
