<?php

namespace Modules\Loyalty\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Loyalty\Models\LoyaltyCustomer;

class LoyaltyCustomerFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = LoyaltyCustomer::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $pointsBalance = $this->faker->numberBetween(0, 2000);
        $lifetimePoints = $pointsBalance + $this->faker->numberBetween(1000, 10000);

        return [
            'points_balance' => $pointsBalance,
            'lifetime_points' => $lifetimePoints,
            'last_earned_at' => $this->faker->optional()->dateTimeBetween('-3 months', 'now'),
            'last_redeemed_at' => $this->faker->optional()->dateTimeBetween('-6 months', 'now'),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}

