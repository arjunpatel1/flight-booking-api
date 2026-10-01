<?php

namespace Modules\Option\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Option\Models\OptionValue;
use Modules\Support\Enums\PriceType;

class OptionValueFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = OptionValue::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'price' => $this->faker->randomFloat(2, 0, 5),
            'price_type' => $this->faker->randomElement(PriceType::values()),
        ];
    }
}

