<?php

namespace Modules\Product\Services\Product;

use App\NexDine;
use Modules\Core\Traits\Cacheable;
use Arr;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Category\Models\Category;
use Modules\Inventory\Models\Ingredient;
use Modules\Menu\Models\Menu;
use Modules\Option\Enums\OptionType;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\OrderProduct;
use Modules\Option\Models\Option;
use Modules\Product\Enums\IngredientOperation;
use Modules\Product\Models\Product;
use Modules\Pricing\Models\PriceType as PricingPriceType;
use Modules\Support\Enums\PriceType;
use Modules\Support\GlobalStructureFilters;
use Modules\Tax\Models\Tax;

class ProductService implements ProductServiceInterface
{
    use Cacheable;

    public function __construct()
    {
        $this->cachePrefix = 'products';
        $this->cacheDuration = 300;
    }

    /** @inheritDoc */
    public function label(): string
    {
        return __("product::products.product");
    }

    /** @inheritDoc */
    public function show(int|string $id, ?int $menuId = null): Product
    {
        return $this->getModel()
            ->query()
            ->with([
                "categories:id,menu_id",
                "taxes:id,branch_id",
                "files",
                "options" => fn($query) => $query->with(['values' => fn($query) => $query->with('ingredients')]),
                "ingredients",
                "productPrices",
            ])
            ->when(!is_null($menuId), fn(Builder $query) => $query->where('menu_id', $menuId))
            ->withoutGlobalActive()
            ->where(is_numeric($id) ? 'id' : 'uuid', $id)
            ->firstOrFail();
    }

    /** @inheritDoc */
    public function findOrFail(int|string $id): Builder|array|EloquentCollection|Product
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->where(is_numeric($id) ? 'id' : 'uuid', $id)
            ->firstOrFail();
    }

    /** @inheritDoc */
    public function getModel(): Product
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Product::class;
    }

    /** @inheritDoc */
    public function store(array $data): Product
    {
        $product = $this->getModel()
            ->query()
            ->create(
                Arr::except(
                    $data,
                    ['categories', 'options', 'ingredients', 'taxes', 'product_prices', 'files']
                )
            );

        $product->categories()->attach(Arr::get($data, 'categories', []));
        $product->taxes()->attach(Arr::get($data, 'taxes', []));
        $this->syncProductPrices($product, Arr::get($data, 'product_prices', []));
        $this->flushMenuCaches();

        return $product;
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->with(['files'])
            ->withoutGlobalActive()
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function update(int|string $id, array $data): Product
    {
        $product = $this->findOrFail($id);
        $product->update(
            Arr::except(
                $data,
                ['categories', 'options', 'ingredients', 'taxes', 'product_prices', 'files']
            )
        );

        if (isset($data['categories'])) {
            $product->categories()->sync(Arr::get($data, 'categories', []));
        }
        if (isset($data['taxes'])) {
            $product->taxes()->sync(Arr::get($data, 'taxes', []));
        }
        if (isset($data['product_prices'])) {
            $this->syncProductPrices($product, $data['product_prices']);
        }

        $this->flushMenuCaches();

        return $product;
    }

    private function flushMenuCaches(): void
    {
        if (method_exists(Cache::getStore(), 'tags')) {
            Cache::tags(['products', 'categories'])->flush();

            return;
        }

        Cache::flush();
    }

    /**
     * Sync product prices by price type.
     *
     * @param Product $product
     * @param array $productPrices
     * @return void
     */
    private function syncProductPrices(Product $product, array $productPrices): void
    {
        $upsertData = collect($productPrices)->map(fn(array $item) => [
            'product_id'    => $product->id,
            'price_type_id' => $item['price_type_id'],
            'is_global'     => (bool) ($item['is_global'] ?? false),
            'price'         => ($item['is_global'] ?? false) ? null : $item['price'],
        ])->all();

        $incomingTypeIds = collect($upsertData)->pluck('price_type_id')->all();

        $product->productPrices()->whereNotIn('price_type_id', $incomingTypeIds)->delete();

        foreach ($upsertData as $row) {
            $product->productPrices()->updateOrCreate(
                ['price_type_id' => $row['price_type_id']],
                ['price'         => $row['price'], 'is_global' => $row['is_global']]
            );
        }
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        return DB::transaction(function () use ($ids): bool {
            $query = $this->getModel()
                ->query()
                ->withoutGlobalActive();
            if (is_string($ids) && Str::isUuid($ids)) {
                $query->where('uuid', $ids);
            } else {
                $query->whereIn('id', parseIds($ids));
            }

            // The database uniqueness constraint includes soft-deleted rows.
            // Release the SKU before the soft delete so it can be reused safely.
            (clone $query)->update(['sku' => null]);
            $deleted = $query->delete() ?: false;
            $this->flushMenuCaches();

            return $deleted;
        });
    }

    /** @inheritDoc */
    public function getStructureFilters(): array
    {
        return [
            GlobalStructureFilters::active(),
            [
                "key" => 'is_recommended',
                "label" => __('product::products.filters.recommended'),
                "type" => 'select',
                "options" => [
                    ["id" => 1, "name" => __('admin::admin.filters.yes')],
                    ["id" => 0, "name" => __('admin::admin.filters.no')],
                ],
            ],
            [
                "key" => 'is_best_seller',
                "label" => __('product::products.filters.best_seller'),
                "type" => 'select',
                "options" => [
                    ["id" => 1, "name" => __('admin::admin.filters.yes')],
                    ["id" => 0, "name" => __('admin::admin.filters.no')],
                ],
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function getFormMeta(?Menu $menu = null): array
    {
        return [
            "menu_id" => $menu?->id,
            "categories" => !is_null($menu) ? Category::treeList($menu->id) : [],
            "taxes" => !is_null($menu) ? Tax::list($menu->branch_id, false) : [],
            "price_types" => PriceType::toArrayTrans(),
            "product_price_types" => PricingPriceType::list(),
            "option_types" => OptionType::toArrayTrans(),
            "option_templates" => !is_null($menu) ? Option::listGlobal() : [],
            "currency" => !is_null($menu) ? $menu->branch->currency : setting("default_currency"),
            "ingredients" => !is_null($menu) ? Ingredient::list($menu->branch_id) : [],
            "ingredient_operations" => IngredientOperation::toArrayTrans()
        ];
    }

    public function merchandisingRecommendations(?Menu $menu = null): array
    {
        $days = min(max((int) setting('product_merchandising_window_days', 30), 7), 180);
        $limit = min(max((int) setting('product_merchandising_limit', 5), 3), 20);
        $start = now()->subDays($days - 1)->startOfDay();
        $end = now()->endOfDay();

        $sales = OrderProduct::query()
            ->without(['product', 'taxes', 'options'])
            ->whereHas('order', fn(Builder $query) => $query
                ->whereNotIn('status', [
                    OrderStatus::Cancelled->value,
                    OrderStatus::Refunded->value,
                    OrderStatus::Merged->value,
                ])
                ->whereBetween('created_at', [$start, $end]))
            ->whereHas('product', fn(Builder $query) => $query
                ->when($menu, fn(Builder $query) => $query->where('menu_id', $menu->id)))
            ->with('product:id,menu_id,name,is_recommended,is_best_seller,display_priority')
            ->select('product_id')
            ->selectRaw('SUM(quantity) as sold_quantity')
            ->selectRaw('SUM(total * COALESCE(currency_rate, 1)) as total_sales')
            ->groupBy('product_id')
            ->havingRaw('SUM(quantity) > 0')
            ->orderByDesc('sold_quantity')
            ->orderByDesc('total_sales')
            ->limit($limit)
            ->get();

        $bestSellerProductIds = $sales->pluck('product_id')->map(fn($id) => (int) $id)->values();
        $staleBestSellers = Product::query()
            ->when($menu, fn(Builder $query) => $query->where('menu_id', $menu->id))
            ->where('is_best_seller', true)
            ->whereNotIn('id', $bestSellerProductIds)
            ->limit($limit)
            // Product eagerly loads its branch through menu_id. Keep that
            // relation key in this lightweight projection so Eloquent can
            // resolve the default relation without a MissingAttributeException.
            ->get(['id', 'menu_id', 'name', 'is_recommended', 'is_best_seller', 'display_priority']);

        return [
            'window_days' => $days,
            'menu_id' => $menu?->id,
            'best_sellers' => $sales->values()->map(fn(OrderProduct $item, int $index) => [
                'product_id' => (int) $item->product_id,
                'name' => $item->product?->name ?? '-',
                'sold_quantity' => (float) $item->sold_quantity,
                'total_sales' => round((float) $item->total_sales, 2),
                'current' => [
                    'is_recommended' => (bool) $item->product?->is_recommended,
                    'is_best_seller' => (bool) $item->product?->is_best_seller,
                    'display_priority' => (int) ($item->product?->display_priority ?? 0),
                ],
                'suggested' => [
                    'is_recommended' => true,
                    'is_best_seller' => true,
                    'display_priority' => $index + 1,
                ],
            ])->all(),
            'stale_best_sellers' => $staleBestSellers->map(fn(Product $product) => [
                'product_id' => $product->id,
                'name' => $product->name,
                'current' => [
                    'is_recommended' => (bool) $product->is_recommended,
                    'is_best_seller' => (bool) $product->is_best_seller,
                    'display_priority' => (int) $product->display_priority,
                ],
                'suggested' => [
                    'is_best_seller' => false,
                ],
            ])->values()->all(),
        ];
    }

    public function applyMerchandisingRecommendations(array $data, ?Menu $menu = null): array
    {
        $recommendations = $this->merchandisingRecommendations($menu);
        $allowedProductIds = collect($recommendations['best_sellers'])
            ->pluck('product_id')
            ->merge(collect($recommendations['stale_best_sellers'])->pluck('product_id'))
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        $requestedProductIds = collect($data['product_ids'] ?? $allowedProductIds)
            ->map(fn($id) => (int) $id)
            ->intersect($allowedProductIds)
            ->values();

        $bestSellerSuggestions = collect($recommendations['best_sellers'])
            ->whereIn('product_id', $requestedProductIds)
            ->values();
        $staleSuggestions = collect($recommendations['stale_best_sellers'])
            ->whereIn('product_id', $requestedProductIds)
            ->values();

        DB::transaction(function () use ($bestSellerSuggestions, $staleSuggestions) {
            foreach ($bestSellerSuggestions as $suggestion) {
                Product::query()
                    ->whereKey($suggestion['product_id'])
                    ->update([
                        'is_recommended' => true,
                        'is_best_seller' => true,
                        'display_priority' => $suggestion['suggested']['display_priority'],
                    ]);
            }

            foreach ($staleSuggestions as $suggestion) {
                Product::query()
                    ->whereKey($suggestion['product_id'])
                    ->update([
                        'is_best_seller' => false,
                    ]);
            }
        });
        $this->flushMenuCaches();

        return [
            'updated' => $bestSellerSuggestions->count() + $staleSuggestions->count(),
            'best_sellers_updated' => $bestSellerSuggestions->count(),
            'stale_best_sellers_updated' => $staleSuggestions->count(),
        ];
    }
}
