<?php

namespace Modules\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\Ingredient;
use Modules\Inventory\Models\Unit;

class IngredientFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Ingredient::class;

    /**
     *
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => ['en' => $this->faker->unique()->words(2, true)],
            'unit_id' => fn () => Unit::query()->create([
                'name' => ['en' => $this->faker->unique()->word()],
                'symbol' => ['en' => $this->faker->unique()->lexify('???')],
                'type' => 'mass',
            ])->id,
            'cost_per_unit' => $this->faker->randomFloat(3, 0.01, 10),
            'alert_quantity' => $this->faker->numberBetween(1, 20),
            'current_stock' => $this->faker->randomFloat(2, 0, 100),
            'is_returnable' => $this->faker->boolean(),
        ];
    }
}
