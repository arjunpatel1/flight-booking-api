<?php

namespace Modules\Inventory\Database\Factories;


use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\Ingredient;
use Modules\Inventory\Models\Purchase;
use Modules\Inventory\Models\PurchaseItem;

class PurchaseItemFactory extends Factory
{
    protected $model = PurchaseItem::class;


    public function definition(): array
    {
        $qty = $this->faker->randomFloat(3, 1, 50);
        $cost = $this->faker->randomFloat(3, 0.05, 15);

        return [
            'purchase_id' => null,
            'ingredient_id' => null,
            'quantity' => $qty,
            'received_quantity' => 0,
            'currency' => 'INR',
            'unit_cost' => $cost,
            'line_total' => bcmul((string)$qty, (string)$cost, 3),
        ];
    }

    /**
     * Ensure the ingredient belongs to the same branch as the purchase.
     */
    public function forPurchase(Purchase $purchase): static
    {
        return $this->state(function () use ($purchase) {
            $ingredientId = Ingredient::query()
                ->select('id', 'unit_id')
                ->inRandomOrder()
                ->where('branch_id', $purchase->branch_id)
                ->first()->id;

            $qty = $this->faker->randomFloat(3, 1, 50);
            $cost = $this->faker->randomFloat(3, 0.05, 15);

            return [
                'purchase_id' => $purchase->id,
                'ingredient_id' => $ingredientId,
                'currency' => $purchase->currency,
                'quantity' => $qty,
                'unit_cost' => $cost,
                'line_total' => bcmul((string)$qty, (string)$cost, 3),
            ];
        });
    }
}
