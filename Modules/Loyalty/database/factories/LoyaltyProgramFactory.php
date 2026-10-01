<?php

namespace Modules\Loyalty\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Loyalty\Models\LoyaltyProgram;

class LoyaltyProgramFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = LoyaltyProgram::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true) . ' Loyalty Program',
            'earning_rate' => $this->faker->randomFloat(2, 0.5, 2.0),
            'redemption_rate' => $this->faker->randomFloat(4, 0.005, 0.02),
            'min_redeem_points' => $this->faker->numberBetween(50, 200),
            'points_expire_after' => $this->faker->optional()->numberBetween(180, 730),
            'is_active' => false,
        ];
    }

    /**
     * State: active program
     */
    public function active(): static
    {
        return $this->state(fn() => ['is_active' => true]);
    }
}

