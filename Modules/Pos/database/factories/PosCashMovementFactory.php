<?php

namespace Modules\Pos\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Pos\Enums\PosCashDirection;
use Modules\Pos\Enums\PosCashReason;
use Modules\Pos\Models\PosCashMovement;

class PosCashMovementFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = PosCashMovement::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $direction = $this->faker->randomElement(PosCashDirection::values());
        $reason = match ($direction) {
            'in' => $this->faker->randomElement([PosCashReason::Sale->value, PosCashReason::PayIn->value, PosCashReason::TipIn->value]),
            'out' => $this->faker->randomElement([PosCashReason::Refund->value, PosCashReason::PayOut->value, PosCashReason::CashDrop->value, PosCashReason::TipOut->value]),
            'adjust' => $this->faker->randomElement([PosCashReason::ClosingAdjust->value, PosCashReason::Correction->value]),
        };

        return [
            'branch_id' => null,
            'pos_register_id' => null,
            'pos_session_id' => null,
            'created_by' => null,

            'direction' => $direction,
            'reason' => $reason,

            'amount' => $this->faker->randomFloat(2, 1, 100),
            'currency' => 'INR',
            'currency_rate' => 1,
            'balance_before' => 0,
            'balance_after' => 0,

            'reference' => $this->faker->boolean(20) ? strtoupper($this->faker->bothify('REF-######')) : null,
            'notes' => $this->faker->boolean(15) ? $this->faker->sentence() : null,
            'occurred_at' => $this->faker->dateTimeBetween('-5 days'),
        ];
    }

    public function in(): self
    {
        return $this->state(fn() => ['direction' => PosCashDirection::In->value]);
    }

    public function out(): self
    {
        return $this->state(fn() => ['direction' => PosCashDirection::Out->value]);
    }

    public function adjust(): self
    {
        return $this->state(fn() => ['direction' => PosCashDirection::Adjust->value]);
    }
}

