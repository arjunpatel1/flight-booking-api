<?php


namespace Modules\Inventory\Services\StockSync;


use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Models\Ingredient;
use Modules\Inventory\Services\InventoryAlert\InventoryAlertServiceInterface;
use Modules\Inventory\Services\StockMovement\StockMovementServiceInterface;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderProduct;
use Modules\Order\Models\OrderProductOption;
use Modules\Product\Models\Ingredientable;
use Modules\Product\Models\Product;
use Throwable;

class StockSyncService implements StockSyncServiceInterface
{
    /** @inheritDoc */
    public function deductOrderStock(Order $order): void
    {
        if ($order->is_stock_deducted) {
            return;
        }

        $this->sync($order);
    }

    /** @inheritDoc */
    public function resyncOrderStock(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order = Order::query()->whereKey($order->id)->firstOrFail();

            if ($order->is_stock_deducted) {
                $this->restoreOrderStock($order);
                $order->refresh();
            }

            $this->deductOrderStock($order);
        });
    }

    /**
     * Sync
     *
     * @param Order $order
     * @param bool $isIn
     * @return void
     * @throws Throwable
     */
    private function sync(Order $order, bool $isIn = false): void
    {
        $syncedIngredientIds = collect();

        DB::transaction(function () use ($order, $isIn, $syncedIngredientIds) {
            $order = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($isIn && !$order->is_stock_deducted) {
                return;
            }

            if (!$isIn && $order->is_stock_deducted) {
                return;
            }

            $order->load([
                "products" => fn($query) => $query->with([
                    "product" => fn($query) => $query->with([
                        "ingredients" => fn($query) => $query
                            ->select("id", "ingredientable_id", "ingredientable_type", "quantity", "loss_pct", "ingredient_id")
                            ->with("ingredient:id,name,branch_id,cost_per_unit,current_stock,is_returnable,unit_id")
                    ]),
                    "options" => fn($query) => $query->with([
                        "values" => fn($query) => $query->with([
                            "ingredients" => fn($query) => $query
                                ->select("id", "ingredientable_id", "ingredientable_type", "operation", "quantity", "loss_pct", "ingredient_id")
                                ->with("ingredient:id,name,branch_id,cost_per_unit,current_stock,is_returnable,unit_id")
                        ])
                    ]),
                ])
            ]);

            if (!$order->products->count()) return;

            $totalCostPrice = 0;
            $totalRevenue = 0;
            $stockRequirements = collect();
            $resolvedOrderLines = collect();

            /** @var OrderProduct $orderProduct */
            foreach ($order->products as $orderProduct) {
                if (in_array($orderProduct->status, [OrderProductStatus::Cancelled, OrderProductStatus::Refunded], true)) {
                    continue;
                }

                $lines = $this->resolveProductBom(
                    product: $orderProduct->product,
                    values: $orderProduct
                        ->options
                        ->map(fn(OrderProductOption $option) => $option->values)
                        ->flatMap
                        ->values(),
                    branchId: $order->branch_id
                );

                $totalOrderProductCostPrice = 0;

                foreach ($lines as $line) {
                    $quantity = (float)$line->quantity;

                    if (isset($line->loss_pct)) {
                        $quantity += $quantity * ((float)$line->loss_pct / 100);
                    }

                    $totalQuantity = $quantity * (float)$orderProduct->quantity;
                    $totalOrderProductCostPrice += $line->ingredient->cost_per_unit->amount() * $totalQuantity;

                    if (!$isIn) {
                        $stockRequirements[$line->ingredient_id] = [
                            'ingredient' => $line->ingredient,
                            'quantity' => (float)($stockRequirements[$line->ingredient_id]['quantity'] ?? 0) + $totalQuantity,
                        ];
                    }

                    $resolvedOrderLines->push([
                        'order_product' => $orderProduct,
                        'line' => $line,
                        'quantity' => $totalQuantity,
                    ]);
                }

                if (!$isIn) {
                    $revenue = $orderProduct->subtotal->amount() - $totalOrderProductCostPrice;

                    $totalCostPrice += $totalOrderProductCostPrice;
                    $totalRevenue += $revenue;

                    $orderProduct->update([
                        "cost_price" => $totalOrderProductCostPrice,
                        "revenue" => $revenue,
                    ]);
                }
            }

            if (!$isIn && $this->shouldBlockOrderOnShortStock()) {
                $this->validateStockAvailability($stockRequirements);
            }

            foreach ($resolvedOrderLines as $resolvedLine) {
                /** @var Ingredientable $line */
                $line = $resolvedLine['line'];
                $totalQuantity = $resolvedLine['quantity'];

                if ($isIn && !$line->ingredient->is_returnable) {
                    continue;
                }

                $syncedIngredientIds->push($line->ingredient_id);

                app(StockMovementServiceInterface::class)
                    ->store([
                        'branch_id' => $order->branch_id,
                        "ingredient_id" => $line->ingredient_id,
                        "type" => $isIn ? StockMovementType::In : StockMovementType::Out,
                        "source_id" => $order->id,
                        "source_type" => Order::class,
                        "quantity" => $totalQuantity,
                        "note" => $isIn
                            ? __("inventory::stock_movements.notes.restocked_order", ["reference" => $order->reference_no])
                            : __("inventory::stock_movements.notes.deducted_order", ["reference" => $order->reference_no]),
                    ]);
            }

            if (!$isIn) {
                $order->update([
                    "cost_price" => $totalCostPrice,
                    "revenue" => $totalRevenue,
                    "is_stock_deducted" => true,
                ]);
            } else {
                $order->update([
                    "is_stock_deducted" => false,
                ]);
            }
        });

        $this->notifyLowStock($syncedIngredientIds);
    }

    /** @inheritDoc */
    public function resolveProductBom(Product $product, Collection $values, int $branchId): Collection
    {
        $lines = $product->ingredients
            ->groupBy('ingredient_id')
            ->map(function ($group) {
                $first = $group->first()->replicate();
                $first->quantity = $group->sum('quantity');
                $first->loss_pct = $group->sum('loss_pct');
                return $first;
            })
            ->keyBy('ingredient_id');

        foreach ($values as $value) {
            $mods = $value->ingredients
                ->groupBy('ingredient_id')
                ->map(function ($group) {
                    $first = $group->first()->replicate();
                    $first->quantity = $group->sum('quantity');
                    $first->loss_pct = $group->sum('loss_pct');
                    return $first;
                });

            /** @var Ingredientable $m */
            foreach ($mods as $m) {
                $id = $m->ingredient_id;

                switch ($m->operation->value) {
                    case 'remove':
                        $lines->forget($id);
                        break;
                    case 'add':
                        if (!$lines->has($id)) {
                            $lines[$id] = $m;
                        } else {
                            $existing = $lines[$id];
                            $existing->quantity += $m->quantity;
                        }
                        break;

                    case 'subtract':
                        if ($lines->has($id)) {
                            $existing = $lines[$id];
                            $existing->quantity -= $m->quantity;
                            if ($existing->quantity <= 0) {
                                $lines->forget($id);
                            }
                        }
                        break;
                    case 'replace':
                        $lines[$id] = $m;
                        break;
                    case 'multiply':
                        if ($lines->has($id)) {
                            $existing = $lines[$id];
                            $existing->quantity *= $m->quantity;
                        }
                        break;
                }

                if (isset($m->loss_pct) && $lines->has($id)) {
                    $lines[$id]->loss_pct = (float)$lines[$id]->loss_pct + (float)$m->loss_pct;
                }
            }
        }

        return $lines
            ->filter(fn(Ingredientable $line) => $line->ingredient?->branch_id === $branchId)
            ->values();
    }

    /** @inheritDoc */
    public function restoreOrderStock(Order $order): void
    {
        $this->sync($order, true);
    }

    /**
     * Prevent order deduction from pushing ingredient stock below zero.
     *
     * @param Collection $stockRequirements
     * @return void
     * @throws ValidationException
     */
    private function validateStockAvailability(Collection $stockRequirements): void
    {
        if ($stockRequirements->isEmpty()) {
            return;
        }

        $ingredients = Ingredient::query()
            ->with('unit:id,symbol')
            ->whereIn('id', $stockRequirements->keys())
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $shortages = $stockRequirements
            ->map(function (array $requirement, int|string $ingredientId) use ($ingredients) {
                $ingredient = $ingredients->get($ingredientId) ?? $requirement['ingredient'];
                $requiredQuantity = (float)$requirement['quantity'];

                if ((float)$ingredient->current_stock >= $requiredQuantity) {
                    return null;
                }

                return __('inventory::messages.insufficient_order_stock_item', [
                    'ingredient' => $ingredient->name,
                    'required' => round($requiredQuantity, 4),
                    'available' => round((float)$ingredient->current_stock, 4),
                    'unit' => $ingredient->unit?->symbol ?? '',
                ]);
            })
            ->filter()
            ->values();

        if ($shortages->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'inventory' => __('inventory::messages.insufficient_order_stock', [
                'items' => $shortages->implode(', '),
            ]),
        ]);
    }

    private function notifyLowStock(Collection $ingredientIds): void
    {
        try {
            app(InventoryAlertServiceInterface::class)->notifyLowStockForIngredients($ingredientIds);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function shouldBlockOrderOnShortStock(): bool
    {
        return (bool) setting('inventory_block_order_on_short_stock', false);
    }

}
