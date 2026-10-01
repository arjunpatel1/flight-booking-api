<?php

namespace Modules\Inventory\Services\RecipeCost;

use Illuminate\Support\Collection;
use Modules\Product\Models\Product;

interface RecipeCostCalculatorInterface
{
    /**
     * Calculate the total ingredient cost for a product.
     *
     * @param Product $product
     * @return array{
     *     total_cost: float,
     *     currency: string,
     *     breakdown: array<int, array{ingredient_id: int, name: string, quantity: float, unit_cost: float, total: float}>
     * }
     */
    public function calculateProductCost(Product $product): array;

    /**
     * Calculate cost for multiple products.
     *
     * @param Collection<int, Product> $products
     * @return Collection<int, array{product_id: int, total_cost: float, currency: string, breakdown: array}>
     */
    public function calculateBatchCosts(Collection $products): Collection;

    /**
     * Calculate food cost margin for a product.
     *
     * @param Product $product
     * @param float|null $sellingPrice Override selling price (uses product price if null)
     * @return array{
     *     ingredient_cost: float,
     *     selling_price: float,
     *     gross_profit: float,
     *     food_cost_percentage: float,
     *     profit_margin_percentage: float,
     *     status: 'healthy'|'warning'|'critical'
     * }
     */
    public function calculateFoodMargin(Product $product, ?float $sellingPrice = null): array;

    /**
     * Get cost analysis for menu items.
     *
     * @param int|null $menuId
     * @param int|null $branchId
     * @return Collection<int, array{
     *     product_id: int,
     *     name: string,
     *     ingredient_cost: float,
     *     selling_price: float,
     *     food_cost_pct: float,
     *     profit_margin_pct: float,
     *     status: string
     * }>
     */
    public function getMenuCostAnalysis(?int $menuId = null, ?int $branchId = null): Collection;

    /**
     * Get products with high food costs (above threshold).
     *
     * @param float $thresholdPercentage
     * @param int|null $branchId
     * @return Collection<int, Product>
     */
    public function getHighCostProducts(float $thresholdPercentage = 35.0, ?int $branchId = null): Collection;

    /**
     * Update product cost cache.
     *
     * @param int $productId
     * @return array{cached_cost: float, calculated_at: string}
     */
    public function updateCostCache(int $productId): array;

    /**
     * Get recommended selling price based on target food cost percentage.
     *
     * @param Product $product
     * @param float $targetFoodCostPercentage
     * @return array{
     *     ingredient_cost: float,
     *     recommended_price: float,
     *     target_food_cost_pct: float,
     *     current_price: float,
     *     price_adjustment_needed: float
     * }
     */
    public function getRecommendedPrice(Product $product, float $targetFoodCostPercentage = 30.0): array;
}
