<?php

namespace Modules\SeatingPlan\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SeatingPlan\Enums\TableShape;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Table;

class TableFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Table::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'zone_id' => null,
            'branch_id' => null,
            'uuid' => (string)Str::uuid(),
            'name' => 'T',
            'capacity' => $this->faker->numberBetween(2, 8),
            'shape' => $this->faker->randomElement(TableShape::values()),
            'status' => $this->faker->randomElement(TableStatus::values()),
            "is_active" => true
        ];
    }
}

