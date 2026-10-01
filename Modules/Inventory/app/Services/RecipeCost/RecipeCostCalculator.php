<?php

namespace Modules\Inventory\Services\RecipeCost;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Inventory\Models\Ingredient;
use Modules\Product\Models\Ingredientable;
use Modules\Product\Models\Product;

class RecipeCostCalculator implements RecipeCostCalculatorInterface
{
    /** @inheritDoc */
    public function calculateProductCost(Product $product): array
    {
        $product->loadMissing([
            'ingredients.ingredient.unit',
            'options.values.ingredients.ingredient.unit',
        ]);

        $breakdown = [];
        $totalCost = 0.0;

        foreach ($product->ingredients as $ingredientable) {
            $ingredient = $ingredientable->ingredient;

            if (!$ingredient) {
                continue;
            }

            $quantity = $this->lineQuantityWithLoss($ingredientable);
            $unitCost = $this->ingredientUnitCost($ingredientable);
            $lineTotal = $this->lineCost($ingredientable);
            $totalCost += $lineTotal;

            $breakdown[] = [
                'ingredient_id' => $ingredient->id,
                'name' => $ingredient->name,
                'quantity' => $quantity,
                'unit' => $ingredient->unit?->symbol ?? '',
                'unit_cost' => $unitCost,
                'loss_pct' => $ingredientable->loss_pct,
                'total' => round($lineTotal, 4),
            ];
        }

        $modifierCosts = $this->calculateModifierCostRange($product);

        return [
            'total_cost' => round($totalCost, 4),
            'currency' => $product->currency,
            'breakdown' => $breakdown,
            'has_modifiers' => $modifierCosts['has_modifiers'],
            'modifier_cost_min' => $modifierCosts['modifier_cost_min'],
            'modifier_cost_max' => $modifierCosts['modifier_cost_max'],
            'min_possible_cost' => round($totalCost + $modifierCosts['modifier_cost_min'], 4),
            'max_possible_cost' => round($totalCost + $modifierCosts['modifier_cost_max'], 4),
            'modifier_breakdown' => $modifierCosts['modifier_breakdown'],
        ];
    }

    /** @inheritDoc */
    public function calculateBatchCosts(Collection $products): Collection
    {
        return $products->map(fn(Product $product) => [
            'product_id' => $product->id,
            ...$this->calculateProductCost($product),
        ]);
    }

    /** @inheritDoc */
    public function calculateFoodMargin(Product $product, ?float $sellingPrice = null): array
    {
        $cost = $this->calculateProductCost($product);
        $ingredientCost = $cost['total_cost'];
        $maxIngredientCost = $cost['max_possible_cost'] ?? $ingredientCost;
        $sellingPrice ??= $product->selling_price?->amount() ?? $product->price?->amount() ?? 0;

        $grossProfit = $sellingPrice - $ingredientCost;
        $maxCostGrossProfit = $sellingPrice - $maxIngredientCost;

        $foodCostPercentage = $sellingPrice > 0
            ? round(($ingredientCost / $sellingPrice) * 100, 2)
            : 0;

        $profitMarginPercentage = $sellingPrice > 0
            ? round(($grossProfit / $sellingPrice) * 100, 2)
            : 0;

        $maxFoodCostPercentage = $sellingPrice > 0
            ? round(($maxIngredientCost / $sellingPrice) * 100, 2)
            : 0;

        // Determine status based on food cost percentage
        $status = match (true) {
            $foodCostPercentage <= 30 => 'healthy',
            $foodCostPercentage <= 40 => 'warning',
            default => 'critical',
        };

        return [
            'ingredient_cost' => $ingredientCost,
            'selling_price' => $sellingPrice,
            'gross_profit' => round($grossProfit, 4),
            'max_cost_gross_profit' => round($maxCostGrossProfit, 4),
            'food_cost_percentage' => $foodCostPercentage,
            'max_food_cost_percentage' => $maxFoodCostPercentage,
            'profit_margin_percentage' => $profitMarginPercentage,
            'status' => $status,
            'modifier_cost_min' => $cost['modifier_cost_min'] ?? 0,
            'modifier_cost_max' => $cost['modifier_cost_max'] ?? 0,
        ];
    }

    /** @inheritDoc */
    public function getMenuCostAnalysis(?int $menuId = null, ?int $branchId = null): Collection
    {
        $query = Product::query()
            ->with(['ingredients.ingredient.unit', 'options.values.ingredients.ingredient.unit', 'categories', 'files']);

        if ($menuId) {
            $query->whereMenu($menuId);
        }

        if ($branchId) {
            $query->whereHas('menu', fn($q) => $q->where('branch_id', $branchId));
        }

        $products = $query->get();

        return $products->map(function (Product $product) {
            $margin = $this->calculateFoodMargin($product);

            return [
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'image_url' => $product->thumbnail?->url ?? null,
                'ingredient_cost' => $margin['ingredient_cost'],
                'selling_price' => $margin['selling_price'],
                'gross_profit' => $margin['gross_profit'],
                'food_cost_pct' => $margin['food_cost_percentage'],
                'max_food_cost_pct' => $margin['max_food_cost_percentage'],
                'profit_margin_pct' => $margin['profit_margin_percentage'],
                'status' => $margin['status'],
                'modifier_cost_min' => $margin['modifier_cost_min'],
                'modifier_cost_max' => $margin['modifier_cost_max'],
                'margin_risk' => $margin['max_food_cost_percentage'] > 40
                    ? 'modifier_risk'
                    : $margin['status'],
                'category' => $product->categories->first()?->name ?? '-',
            ];
        });
    }

    /** @inheritDoc */
    public function getHighCostProducts(float $thresholdPercentage = 35.0, ?int $branchId = null): Collection
    {
        $products = Product::query()
            ->with(['ingredients.ingredient.unit', 'options.values.ingredients.ingredient.unit'])
            ->whereHas('ingredients')
            ->when($branchId, fn($q) => $q->whereHas('menu', fn($menuQuery) => $menuQuery->where('branch_id', $branchId)))
            ->get();

        return $products->filter(function (Product $product) use ($thresholdPercentage) {
            $margin = $this->calculateFoodMargin($product);
            return $margin['food_cost_percentage'] > $thresholdPercentage;
        })->values();
    }

    /** @inheritDoc */
    public function updateCostCache(int $productId): array
    {
        $product = Product::with(['ingredients.ingredient.unit', 'options.values.ingredients.ingredient.unit'])->findOrFail($productId);
        $cost = $this->calculateProductCost($product);

        $cacheKey = "product:{$productId}:ingredient_cost";
        $cacheData = [
            'cached_cost' => $cost['total_cost'],
            'currency' => $cost['currency'],
            'calculated_at' => now()->toIso8601String(),
        ];

        Cache::tags(['products', 'ingredients'])
            ->put($cacheKey, $cacheData, now()->addHours(24));

        return $cacheData;
    }

    /** @inheritDoc */
    public function getRecommendedPrice(Product $product, float $targetFoodCostPercentage = 30.0): array
    {
        $cost = $this->calculateProductCost($product);
        $ingredientCost = $cost['total_cost'];

        // Calculate recommended price: cost / (target_percentage / 100)
        $recommendedPrice = $ingredientCost > 0
            ? $ingredientCost / ($targetFoodCostPercentage / 100)
            : 0;

        $currentPrice = $product->selling_price?->amount() ?? $product->price?->amount() ?? 0;

        return [
            'ingredient_cost' => $ingredientCost,
            'max_possible_cost' => $cost['max_possible_cost'] ?? $ingredientCost,
            'recommended_price' => round($recommendedPrice, 2),
            'target_food_cost_pct' => $targetFoodCostPercentage,
            'current_price' => $currentPrice,
            'price_adjustment_needed' => round($recommendedPrice - $currentPrice, 2),
        ];
    }

    private function calculateModifierCostRange(Product $product): array
    {
        $baseLines = $this->baseLines($product->ingredients);
        $modifierBreakdown = [];
        $minDelta = 0.0;
        $maxDelta = 0.0;

        foreach ($product->options as $option) {
            $valueDeltas = [];

            foreach ($option->values as $value) {
                $delta = 0.0;

                foreach ($value->ingredients as $ingredientable) {
                    $delta += $this->modifierLineDelta($ingredientable, $baseLines);
                }

                $valueDeltas[] = $delta;
                $modifierBreakdown[] = [
                    'option_id' => $option->id,
                    'option_name' => $option->name,
                    'value_id' => $value->id,
                    'value_label' => $value->label,
                    'is_required' => (bool)$option->is_required,
                    'cost_delta' => round($delta, 4),
                    'ingredient_count' => $value->ingredients->count(),
                ];
            }

            if (empty($valueDeltas)) {
                continue;
            }

            $minValue = min($valueDeltas);
            $maxValue = max($valueDeltas);

            $minDelta += $option->is_required ? $minValue : min(0, $minValue);
            $maxDelta += $option->is_required ? $maxValue : max(0, $maxValue);
        }

        return [
            'has_modifiers' => count($modifierBreakdown) > 0,
            'modifier_cost_min' => round($minDelta, 4),
            'modifier_cost_max' => round($maxDelta, 4),
            'modifier_breakdown' => $modifierBreakdown,
        ];
    }

    private function baseLines(Collection $ingredientables): Collection
    {
        return $ingredientables
            ->groupBy('ingredient_id')
            ->map(function (Collection $group) {
                $first = $group->first()->replicate();
                $first->quantity = $group->sum('quantity');
                $first->loss_pct = $group->sum('loss_pct');

                return $first;
            });
    }

    private function modifierLineDelta(Ingredientable $ingredientable, Collection $baseLines): float
    {
        $operation = $ingredientable->operation?->value ?? 'add';
        $lineCost = $this->lineCost($ingredientable);
        $baseLine = $baseLines->get($ingredientable->ingredient_id);
        $baseCost = $baseLine instanceof Ingredientable ? $this->lineCost($baseLine) : 0.0;

        return match ($operation) {
            'subtract' => -min($lineCost, $baseCost),
            'replace' => $lineCost - $baseCost,
            'multiply' => $baseLine instanceof Ingredientable
                ? $baseCost * ((float)$ingredientable->quantity - 1)
                : 0.0,
            default => $lineCost,
        };
    }

    private function lineQuantityWithLoss(Ingredientable $ingredientable): float
    {
        $quantity = (float)$ingredientable->quantity;

        if ($ingredientable->loss_pct > 0) {
            $quantity *= 1 + ((float)$ingredientable->loss_pct / 100);
        }

        return $quantity;
    }

    private function ingredientUnitCost(Ingredientable $ingredientable): float
    {
        return $ingredientable->ingredient?->cost_per_unit?->amount() ?? 0.0;
    }

    private function lineCost(Ingredientable $ingredientable): float
    {
        return $this->lineQuantityWithLoss($ingredientable) * $this->ingredientUnitCost($ingredientable);
    }
}
