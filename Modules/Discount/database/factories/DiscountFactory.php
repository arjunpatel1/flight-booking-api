<?php

namespace Modules\Discount\Database\Factories;

use Arr;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Discount\Models\Discount;
use Modules\Support\Enums\PriceType;

class DiscountFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Discount::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $type = $this->faker->randomElement(PriceType::values());

        // Simulate some conditions
        $conditions = [
            'available_days' => Arr::random([
                ['monday', 'tuesday'],
                ['friday', 'saturday'],
                ['sunday'],
            ]),
            'categories' => [],
            'products' => [],
        ];

        $meta = [];

        // Discount value logic
        $value = $type === PriceType::Percent->value
            ? $this->faker->numberBetween(5, 30) // 5–30% off
            : $this->faker->randomFloat(2, 1, 20); // 1–20 currency units

        // Active period
        $start = Carbon::now()->subDays($this->faker->numberBetween(0, 10));
        $end = (clone $start)->addDays($this->faker->numberBetween(10, 60));

        return [
            'branch_id' => null,
            'name' => [
                'en' => $this->faker->words(3, true),
                'ar' => 'خصم ' . $this->faker->word(),
            ],
            'description' => [
                'en' => $this->faker->sentence(),
                'ar' => 'وصف الخصم ' . $this->faker->word(),
            ],
            'type' => $type,
            'value' => $value,
            'is_active' => $this->faker->boolean(90),
            'conditions' => $conditions,
            'usage_limit' => $this->faker->optional()->numberBetween(50, 500),
            'per_customer_limit' => $this->faker->optional()->numberBetween(1, 5),
            'start_date' => $start,
            'end_date' => $end,
            'minimum_spend' => $this->faker->optional()->randomFloat(2, 5, 50),
            'maximum_spend' => $this->faker->optional()->randomFloat(2, 60, 150),
            'meta' => $meta,
        ];
    }
}
