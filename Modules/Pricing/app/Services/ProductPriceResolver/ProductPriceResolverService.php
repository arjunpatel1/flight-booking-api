<?php

namespace Modules\Pricing\Services\ProductPriceResolver;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Order\Enums\OrderType;
use Modules\Pricing\Models\PriceType;
use Modules\Pricing\Support\PriceTypeRuleCalculator;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductPrice;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\Zone;

class ProductPriceResolverService implements ProductPriceResolverServiceInterface
{
    private const SELF_SERVICE_PRICE_TYPE_CODE = 'SELF_SERVICE';

    /**
     * Resolve the zone price type from a table or zone.
     */
    public function resolvePriceTypeId(?int $tableId = null, ?int $zoneId = null): ?int
    {
        if ($zoneId) {
            return Zone::query()
                ->withOutGlobalBranchPermission()
                ->withoutGlobalActive()
                ->whereKey($zoneId)
                ->value('price_type_id');
        }

        if ($tableId) {
            return Table::query()
                ->withOutGlobalBranchPermission()
                ->withoutGlobalActive()
                ->whereKey($tableId)
                ->with('zone:id,price_type_id')
                ->first(['id', 'zone_id'])
                ?->zone
                ?->price_type_id;
        }

        return null;
    }

    /**
     * Resolve the price type that should apply for the current order context.
     */
    public function resolvePriceTypeIdForOrderType(?string $orderType, ?int $tableId = null, ?int $zoneId = null): ?int
    {
        return match ($orderType) {
            OrderType::SelfService->value => $this->selfServicePriceTypeId(),
            OrderType::DineIn->value => $this->resolvePriceTypeId($tableId, $zoneId),
            default => null,
        };
    }

    /**
     * Return resolved product prices keyed by product id.
     *
     * @param array<int> $productIds
     * @return Collection<int, float>
     */
    public function priceMap(array $productIds, ?int $priceTypeId): Collection
    {
        $productIds = collect($productIds)
            ->map(fn($id) => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if (! $priceTypeId || $productIds->isEmpty()) {
            return collect();
        }

        $cacheKey = makeCacheKey([
            'product_prices',
            'pricing-policy-v2',
            "price-type-$priceTypeId",
            md5($productIds->implode(',')),
        ]);

        return $this->remember(
            $cacheKey,
            function () use ($priceTypeId, $productIds) {
                $assignments = ProductPrice::query()
                    ->where('price_type_id', $priceTypeId)
                    ->whereIn('product_id', $productIds)
                    ->get(['product_id', 'price', 'is_global']);

                $prices = $assignments
                    ->filter(fn(ProductPrice $price) => ! $price->is_global && (float) $price->price > 0)
                    ->mapWithKeys(fn(ProductPrice $price) => [$price->product_id => (float) $price->price]);

                $globalProductIds = $assignments
                    ->filter(fn(ProductPrice $price) => $price->is_global)
                    ->pluck('product_id')
                    ->values();

                if ($globalProductIds->isEmpty()) {
                    return $prices;
                }

                $priceType = PriceType::query()
                    ->withoutGlobalActive()
                    ->active()
                    ->select('id', 'code', 'rule_type', 'rule_value')
                    ->find($priceTypeId);

                if (! $priceType) {
                    return $prices;
                }

                Product::query()
                    ->toBase()
                    ->whereIn('id', $globalProductIds)
                    ->pluck('price', 'id')
                    ->each(function ($basePrice, $productId) use ($prices, $priceType) {
                        $prices->put((int) $productId, PriceTypeRuleCalculator::resolve(
                            basePrice: (float) $basePrice,
                            ruleType: $priceType->rule_type,
                            ruleValue: $priceType->rule_value,
                            code: $priceType->code,
                        ));
                    });

                return $prices;
            }
        );
    }

    /**
     * Attach resolved POS price metadata to a product collection.
     *
     * @param EloquentCollection<int, Product> $products
     * @return EloquentCollection<int, Product>
     */
    public function applyToProducts(EloquentCollection $products, ?int $priceTypeId): EloquentCollection
    {
        if (! $priceTypeId || $products->isEmpty()) {
            return $products;
        }

        $priceMap = $this->priceMap($products->modelKeys(), $priceTypeId);

        if ($priceMap->isEmpty()) {
            return $products;
        }

        $products->each(function (Product $product) use ($priceMap, $priceTypeId) {
            if (! $priceMap->has($product->id)) {
                return;
            }

            $this->setResolvedPrice($product, $priceMap->get($product->id), $priceTypeId);
        });

        return $products;
    }

    /**
     * Attach resolved POS price metadata to a single product.
     */
    public function applyToProduct(Product $product, ?int $priceTypeId): Product
    {
        if (! $priceTypeId) {
            return $product;
        }

        $price = $this->priceMap([$product->id], $priceTypeId)->get($product->id);

        if (! is_null($price)) {
            $this->setResolvedPrice($product, $price, $priceTypeId);
        }

        return $product;
    }

    /**
     * Mark a product with the price selected for POS ordering.
     */
    private function setResolvedPrice(Product $product, float $price, int $priceTypeId): void
    {
        $product->setAttribute('pos_resolved_price', $price);
        $product->setAttribute('pos_price_type_id', $priceTypeId);
        $product->setAttribute('pos_price_source', 'price_type');
    }

    /**
     * Return the active self-service price type id.
     */
    private function selfServicePriceTypeId(): ?int
    {
        $id = $this->remember(
            makeCacheKey(['price_types', self::SELF_SERVICE_PRICE_TYPE_CODE]),
            fn() => PriceType::query()
                ->withoutGlobalActive()
                ->active()
                ->where('code', self::SELF_SERVICE_PRICE_TYPE_CODE)
                ->value('id')
        );

        return $id ? (int) $id : null;
    }

    /**
     * Remember resolver data with cache tags when the configured store supports them.
     */
    private function remember(string $key, callable $callback): mixed
    {
        $ttl = now()->addMinutes(max((int) setting('pos_menu_cache_minutes', 5), 1));

        if (method_exists(Cache::getStore(), 'tags')) {
            return Cache::tags(['products', 'product_prices', 'price_types'])
                ->remember($key, $ttl, $callback);
        }

        return Cache::remember($key, $ttl, $callback);
    }
}
