<?php

namespace Modules\Loyalty\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Loyalty\Enums\LoyaltyTransactionType;
use Modules\Loyalty\Models\LoyaltyTransaction;

class LoyaltyTransactionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = LoyaltyTransaction::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $type = $this->faker->randomElement(LoyaltyTransactionType::values());

        $points = match ($type) {
            LoyaltyTransactionType::Redeem->value,
            LoyaltyTransactionType::Expire->value => -$this->faker->numberBetween(50, 500),
            default => $this->faker->numberBetween(10, 500),
        };

        $amount = in_array($type, [LoyaltyTransactionType::Earn->value, LoyaltyTransactionType::Redeem->value])
            ? $this->faker->randomFloat(2, 1, 200)
            : null;

        return [
            'type' => $type,
            'points' => $points,
            'amount' => $amount,
            'meta' => [
                ...$this->buildMeta($type),
                "description" => [
                    "text" => "loyalty::loyalty_transactions.type_descriptions.default.$type",
                    "replace" => [
                        "order_id" => "ORD-" . rand(1000, 100000),
                        "points" => $points
                    ]
                ]
            ],
        ];
    }

    /**
     * Build a dynamic meta payload depending on type.
     */
    protected function buildMeta(string $type): ?array
    {
        return match ($type) {
            LoyaltyTransactionType::Earn->value => [
                'program_id' => "PRG-CXLW3LDS",
                'order_id' => "ORD-WEFWEV",
                'source' => 'order',
            ],
            LoyaltyTransactionType::Redeem->value => [
                'order_id' => "ORD-VLRKE",
                'redeem_method' => 'checkout_discount',
            ],
            LoyaltyTransactionType::Bonus->value => [
                'promotion' => 'Weekend Double Points',
                'valid_until' => now()->addDays(2),
            ],
            LoyaltyTransactionType::Adjust->value => [
                'admin_reason' => 'Manual correction',
                'adjusted_by' => 1,
            ],
            LoyaltyTransactionType::Expire->value => [
                'reason' => 'points_expired_after_policy',
            ],
        };
    }

    /**
     * Quick state: Earn transaction only
     */
    public function earn(): static
    {
        return $this->state(fn() => ['type' => LoyaltyTransactionType::Earn->value]);
    }

    /**
     * Quick state: Redeem transaction only
     */
    public function redeem(): static
    {
        return $this->state(fn() => ['type' => LoyaltyTransactionType::Redeem->value]);
    }
}

