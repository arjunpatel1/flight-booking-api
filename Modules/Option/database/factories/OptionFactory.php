<?php

namespace Modules\Option\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Option\Models\Option;

class OptionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Option::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'is_required' => $this->faker->boolean(),
        ];
    }
}

