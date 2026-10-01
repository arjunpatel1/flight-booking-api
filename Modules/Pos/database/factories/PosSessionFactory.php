<?php

namespace Modules\Pos\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Pos\Enums\PosSessionStatus;
use Modules\Pos\Models\PosSession;
use Modules\User\Models\User;
use RuntimeException;

class PosSessionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = PosSession::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'branch_id' => null,
            'pos_register_id' => null,
            'opened_by' => null,
            'closed_by' => null,
            'opened_at' => null,
            'closed_at' => null,
            'status' => PosSessionStatus::Open->value,
            'opening_float' => 0,
            'declared_cash' => null,
            'cash_over_short' => null,
            'system_cash_sales' => null,
            'system_card_sales' => null,
            'system_other_sales' => null,
            'total_sales' => null,
            'total_refunds' => null,
            'orders_count' => null,
            'notes' => null,
        ];

    }

    /**
     * Constrain all FKs to the same branch & set OPEN/CLOSED behavior.
     *
     * @param int $branchId
     * @param int $posRegisterId
     * @param PosSessionStatus $status
     * @param array|null $overrides (optional explicit values e.g. opening_float)
     * @return static
     */
    public function forBranch(int $branchId, int $posRegisterId, PosSessionStatus $status, ?array $overrides = null): self
    {
        return $this->state(function () use ($branchId, $posRegisterId, $status, $overrides) {
            $openedBy = User::query()
                ->where('branch_id', $branchId)
                ->inRandomOrder()
                ->value('id');

            if (!$openedBy) {
                throw new RuntimeException("No users found for branch {$branchId}");
            }

            $openedAt = $this->faker->dateTimeBetween('-14 days', '-1 day');

            $state = [
                'branch_id' => $branchId,
                'pos_register_id' => $posRegisterId,
                'opened_by' => $openedBy,
                'opened_at' => $openedAt,
                'status' => $status->value,
                'opening_float' => $overrides['opening_float'] ?? $this->faker->randomFloat(2, 0, 200),
                'notes' => $overrides['notes'] ?? ($this->faker->boolean(20) ? $this->faker->sentence() : null),
            ];

            if ($status === PosSessionStatus::Open) {
                $state += [
                    'closed_by' => null,
                    'closed_at' => null,
                    'declared_cash' => null,
                    'cash_over_short' => null,

                    'system_cash_sales' => null,
                    'system_card_sales' => null,
                    'system_other_sales' => null,
                    'total_sales' => null,
                    'total_refunds' => null,
                    'orders_count' => null,
                ];
            } else {
                $closedAt = $this->faker->dateTimeBetween($openedAt, 'now');
                $closedBy = User::query()
                    ->where('branch_id', $branchId)
                    ->inRandomOrder()
                    ->value('id') ?? $openedBy;

                $systemCashSales = $this->faker->randomFloat(2, 0, 700);
                $systemCardSales = $this->faker->randomFloat(2, 0, 900);
                $systemWalletSales = $this->faker->randomFloat(2, 0, 300);
                $systemVoucherSales = $this->faker->randomFloat(2, 0, 200);
                $systemOtherSales = $this->faker->randomFloat(2, 0, 120);

                $totalSales = $systemCashSales + $systemCardSales + $systemWalletSales + $systemVoucherSales + $systemOtherSales;
                $totalRefunds = $this->faker->randomFloat(2, 0, 80);

                $declaredCash = $systemCashSales + $this->faker->randomFloat(2, 10, 10);
                $cashOverShort = $declaredCash - $systemCashSales;

                $state += [
                    'closed_by' => $closedBy,
                    'closed_at' => $closedAt,
                    'declared_cash' => $declaredCash,
                    'cash_over_short' => $cashOverShort,

                    'system_cash_sales' => $systemCashSales,
                    'system_card_sales' => $systemCardSales,
                    'system_other_sales' => $systemOtherSales,

                    'total_sales' => $totalSales,
                    'total_refunds' => $totalRefunds,
                    'orders_count' => $this->faker->numberBetween(5, 120),
                ];
            }

            if (is_array($overrides)) {
                $state = array_replace($state, $overrides);
            }

            return $state;
        });
    }
}

