<?php

namespace Modules\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\Supplier;

class SupplierFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Supplier::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            "branch_id" => null,
            "name" => $this->faker->name(),
            "email" => $this->faker->safeEmail(),
            "phone" => $this->faker->phoneNumber(),
            "address" => $this->faker->address(),
        ];
    }
}

