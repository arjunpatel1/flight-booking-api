<?php

namespace Modules\Inventory\Services\RecipeDeduction;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Models\Ingredient;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryAlert\InventoryAlertServiceInterface;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderProduct;
use Modules\Product\Models\Product;
use Throwable;

class RecipeDeductionService implements RecipeDeductionServiceInterface
{
    public function __construct(
        private readonly InventoryAlertServiceInterface $alertService
    ) {
    }

    /** @inheritDoc */
    public function deductForOrder(Order $order): Collection
    {
        $results = collect();

        if (!$order->relationLoaded('products')) {
            $order->load(['products.product.ingredients.ingredient']);
        }

        foreach ($order->products as $orderProduct) {
            $productResults = $this->deductForOrderProduct($orderProduct, $order->branch_id);
            $results = $results->merge($productResults);
        }

        // Notify about low stock after deductions
        $affectedIngredientIds = $results
            ->where('deducted', true)
            ->pluck('ingredient_id')
            ->unique()
            ->values()
            ->toArray();

        if (!empty($affectedIngredientIds)) {
            $this->alertService->notifyLowStockForIngredients($affectedIngredientIds);
        }

        return $results;
    }

    /**
     * Deduct ingredients for a single order product.
     *
     * @param OrderProduct $orderProduct
     * @param int $branchId
     * @return Collection<int, array{ingredient_id: int, quantity: float, deducted: bool, message?: string}>
     */
    private function deductForOrderProduct(OrderProduct $orderProduct, int $branchId): Collection
    {
        $results = collect();
        $product = $orderProduct->product;

        if (!$product || !$product->relationLoaded('ingredients')) {
            $product?->load('ingredients.ingredient');
        }

        if (!$product || $product->ingredients->isEmpty()) {
            return $results;
        }

        foreach ($product->ingredients as $ingredientable) {
            $ingredient = $ingredientable->ingredient;

            if (!$ingredient) {
                continue;
            }

            $requiredQuantity = $ingredientable->quantity * $orderProduct->quantity;

            // Calculate with loss percentage
            if ($ingredientable->loss_pct > 0) {
                $requiredQuantity = $requiredQuantity * (1 + ($ingredientable->loss_pct / 100));
            }

            try {
                $movement = StockMovement::create([
                    'ingredient_id' => $ingredient->id,
                    'branch_id' => $branchId,
                    'type' => StockMovementType::Out,
                    'quantity' => $requiredQuantity,
                    'note' => __('inventory::stock_movements.notes.order_deduction', [
                        'product' => $product->name,
                        'order_id' => $orderProduct->order_id,
                    ]),
                    'source_id' => $orderProduct->order_id,
                    'source_type' => $orderProduct->order?->getMorphClass(),
                ]);

                $results->push([
                    'ingredient_id' => $ingredient->id,
                    'quantity' => $requiredQuantity,
                    'deducted' => true,
                    'movement_id' => $movement->id,
                ]);
            } catch (Throwable $e) {
                Log::error('Recipe deduction failed', [
                    'ingredient_id' => $ingredient->id,
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);

                $results->push([
                    'ingredient_id' => $ingredient->id,
                    'quantity' => $requiredQuantity,
                    'deducted' => false,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /** @inheritDoc */
    public function restoreForOrder(Order $order, ?string $reason = null): Collection
    {
        $results = collect();

        // Find all stock movements for this order
        $movements = StockMovement::where('source_id', $order->id)
            ->where('source_type', $order->getMorphClass())
            ->where('type', StockMovementType::Out)
            ->get();

        foreach ($movements as $movement) {
            try {
                $restoreMovement = StockMovement::create([
                    'ingredient_id' => $movement->ingredient_id,
                    'branch_id' => $movement->branch_id,
                    'type' => StockMovementType::AdjustAdd,
                    'quantity' => $movement->quantity,
                    'note' => $reason ?? __('inventory::stock_movements.notes.order_cancel_restore', [
                        'order_id' => $order->id,
                    ]),
                    'source_id' => $order->id,
                    'source_type' => $order->getMorphClass(),
                ]);

                $results->push([
                    'ingredient_id' => $movement->ingredient_id,
                    'quantity' => $movement->quantity,
                    'restored' => true,
                    'movement_id' => $restoreMovement->id,
                ]);
            } catch (Throwable $e) {
                Log::error('Recipe restore failed', [
                    'ingredient_id' => $movement->ingredient_id,
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);

                $results->push([
                    'ingredient_id' => $movement->ingredient_id,
                    'quantity' => $movement->quantity,
                    'restored' => false,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /** @inheritDoc */
    public function checkAvailability(Order|Collection $orderOrProducts, ?int $branchId = null): array
    {
        if ($orderOrProducts instanceof Order) {
            if (!$orderOrProducts->relationLoaded('products')) {
                $orderOrProducts->load(['products.product.ingredients.ingredient']);
            }
            $products = $orderOrProducts->products;
            $branchId = $branchId ?? $orderOrProducts->branch_id;
        } else {
            $products = $orderOrProducts;
        }

        $requiredIngredients = collect();

        foreach ($products as $orderProduct) {
            $product = $orderProduct->product ?? Product::with('ingredients.ingredient')->find($orderProduct->product_id);

            if (!$product || $product->ingredients->isEmpty()) {
                continue;
            }

            foreach ($product->ingredients as $ingredientable) {
                $ingredient = $ingredientable->ingredient;

                if (!$ingredient) {
                    continue;
                }

                $requiredQuantity = $ingredientable->quantity * $orderProduct->quantity;

                if ($ingredientable->loss_pct > 0) {
                    $requiredQuantity = $requiredQuantity * (1 + ($ingredientable->loss_pct / 100));
                }

                if ($requiredIngredients->has($ingredient->id)) {
                    $requiredIngredients[$ingredient->id]['required'] += $requiredQuantity;
                } else {
                    $requiredIngredients[$ingredient->id] = [
                        'ingredient_id' => $ingredient->id,
                        'name' => $ingredient->name,
                        'required' => $requiredQuantity,
                        'available' => $ingredient->current_stock,
                    ];
                }
            }
        }

        $shortages = $requiredIngredients
            ->filter(fn($item) => $item['required'] > $item['available'])
            ->values()
            ->toArray();

        return [
            'available' => empty($shortages),
            'shortages' => $shortages,
        ];
    }

    /** @inheritDoc */
    public function projectIngredientUsage(Collection $orderProducts): Collection
    {
        $projections = collect();

        foreach ($orderProducts as $orderProduct) {
            $product = $orderProduct->product ?? Product::with('ingredients.ingredient.unit')->find($orderProduct->product_id);

            if (!$product || $product->ingredients->isEmpty()) {
                continue;
            }

            foreach ($product->ingredients as $ingredientable) {
                $ingredient = $ingredientable->ingredient;

                if (!$ingredient) {
                    continue;
                }

                $quantity = $ingredientable->quantity * $orderProduct->quantity;

                if ($ingredientable->loss_pct > 0) {
                    $quantity = $quantity * (1 + ($ingredientable->loss_pct / 100));
                }

                if ($projections->has($ingredient->id)) {
                    $projections[$ingredient->id]['total_quantity'] += $quantity;
                } else {
                    $projections[$ingredient->id] = [
                        'ingredient_id' => $ingredient->id,
                        'name' => $ingredient->name,
                        'total_quantity' => $quantity,
                        'unit' => $ingredient->unit?->symbol ?? '',
                    ];
                }
            }
        }

        return $projections->values();
    }

    /** @inheritDoc */
    public function deductForProduct(int $productId, float $quantity, int $branchId, ?int $orderId = null): Collection
    {
        $product = Product::with('ingredients.ingredient')->find($productId);

        if (!$product || $product->ingredients->isEmpty()) {
            return collect();
        }

        $orderProduct = new OrderProduct([
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);
        $orderProduct->setRelation('product', $product);

        return $this->deductForOrderProduct($orderProduct, $branchId);
    }
}
