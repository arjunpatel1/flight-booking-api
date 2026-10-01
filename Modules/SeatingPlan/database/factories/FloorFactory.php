<?php

namespace Modules\SeatingPlan\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SeatingPlan\Models\Floor;

class FloorFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Floor::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'is_active' => $this->faker->boolean
        ];
    }
}

