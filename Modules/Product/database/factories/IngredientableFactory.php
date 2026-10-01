<?php

namespace Modules\Product\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Product\Enums\IngredientOperation;
use Modules\Product\Models\Ingredientable;

class IngredientableFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Ingredientable::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'ingredient_id' => null,
            'ingredientable_id' => null,
            'ingredientable_type' => null,
            'priority' => null,
            'branch_id' => null,
            'quantity' => $this->faker->randomFloat(3, 0.1, 50),
            'loss_pct' => $this->faker->randomFloat(3, 0, 5),
            'note' => $this->faker->text(100),
            'operation' => $this->faker->randomElement(IngredientOperation::values()),
        ];
    }
}

