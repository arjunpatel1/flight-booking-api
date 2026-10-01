<?php

namespace Modules\Pricing\Services\ProductPriceResolver;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Modules\Product\Models\Product;

interface ProductPriceResolverServiceInterface
{
    /**
     * Resolve the zone price type from a table or zone.
     */
    public function resolvePriceTypeId(?int $tableId = null, ?int $zoneId = null): ?int;

    /**
     * Resolve the price type that should apply for the current order context.
     */
    public function resolvePriceTypeIdForOrderType(?string $orderType, ?int $tableId = null, ?int $zoneId = null): ?int;

    /**
     * Return product specific prices keyed by product id.
     *
     * @param array<int> $productIds
     * @return Collection<int, float>
     */
    public function priceMap(array $productIds, ?int $priceTypeId): Collection;

    /**
     * Attach resolved POS price metadata to a product collection.
     *
     * @param EloquentCollection<int, Product> $products
     * @return EloquentCollection<int, Product>
     */
    public function applyToProducts(EloquentCollection $products, ?int $priceTypeId): EloquentCollection;

    /**
     * Attach resolved POS price metadata to a single product.
     */
    public function applyToProduct(Product $product, ?int $priceTypeId): Product;
}
