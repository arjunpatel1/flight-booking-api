<?php

namespace Modules\Product\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Product\Models\Product;
use Modules\Support\Enums\PriceType;

class ProductFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Product::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $price = $this->faker->randomFloat(3, 0.2, 100);
        $specialPrice = $this->faker->boolean(80)
            ? null
            : $this->faker->randomFloat(3, 0.1, $price);
        return [
            "name" => [],
            "description" => [],
            "menu_id" => null,
            'sku' => strtoupper('SKU-' . $this->faker->unique()->bothify('###??##')),
            "price" => $price,
            "special_price" => $specialPrice,
            "special_price_type" => is_null($specialPrice) ? null : $this->faker->randomElement(PriceType::values()),
            "is_active" => true
        ];
    }
}

