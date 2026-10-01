<?php

namespace Modules\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Models\Ingredient;
use Modules\Inventory\Models\StockMovement;

class StockMovementFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = StockMovement::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'branch_id' => null,
            'ingredient_id' => null,
            'type' => fake()->randomElement(StockMovementType::values()),
            'quantity' => fake()->randomFloat(3, 0.1, 50),
            'note' => fake()->boolean(25) ? fake()->sentence() : null,
        ];
    }


    /**
     * Ensure ingredient belongs to the same branch.
     */
    public function withConsistentBranch(): static
    {
        return $this->state(function (array $attrs) {
            $branch = isset($attrs['branch_id'])
                ? Branch::find($attrs['branch_id'])
                : Branch::inRandomOrder()->first();

            $ingredientId = $attrs['ingredient_id'] ?? Ingredient::query()
                ->where('branch_id', $branch->id)
                ->inRandomOrder()->first()->id;

            return [
                'branch_id' => $branch->id,
                'ingredient_id' => $ingredientId,
            ];
        });
    }
}

