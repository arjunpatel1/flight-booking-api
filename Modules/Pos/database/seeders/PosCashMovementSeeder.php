<?php

namespace Modules\Pos\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Modules\Pos\Database\Factories\PosCashMovementFactory;
use Modules\Pos\Enums\PosCashDirection;
use Modules\Pos\Enums\PosCashReason;
use Modules\Pos\Models\PosCashMovement;
use Modules\Pos\Models\PosSession;
use Modules\User\Models\User;

class PosCashMovementSeeder extends Seeder
{
    public function run(): void
    {
        $sessions = PosSession::query()
            ->with(['posRegister:id,branch_id', 'branch:id,currency', 'openedBy:id,branch_id', 'closedBy:id,branch_id'])
            ->get();


        /** @var PosSession $session */
        foreach ($sessions as $session) {
            $register = $session->posRegister;
            $branch = $session->branch;

            if (!$register || !$branch) {
                continue;
            }

            $currency = $branch->currency ?? 'USD';
            $rate = 1.00;

            // Start running balance from opening_float
            $running = $session->opening_float?->amount() ?: 0;

            // Build a chronological timeline within the session window
            $startAt = Carbon::parse($session->opened_at ?: now()->subHours(8));
            $endAt = Carbon::parse($session->closed_at ?: now());
            if ($endAt->lessThanOrEqualTo($startAt)) {
                $endAt = (clone $startAt)->addHours(6);
            }

            // Clear existing movements for idempotent seeding (optional)
            PosCashMovement::where('pos_session_id', $session->id)->delete();

            // How many events to create
            $eventsCount = rand(8, 25);
            $ticks = $this->distributeTimestamps($startAt, $endAt, $eventsCount);

            for ($i = 0; $i < $eventsCount; $i++) {
                [$direction, $reason, $amount] = $this->randomEvent();

                // Compute balances
                $before = $running;
                if ($direction === PosCashDirection::In->value) {
                    $running += $amount;
                } elseif ($direction === PosCashDirection::Out->value) {
                    $running = max(0, $running - $amount); // no negative cash drawer
                } else {
                    // adjust: does not change physical cash (we keep running)
                }
                $after = $running;

                // Choose a user from this branch
                $userId = User::query()
                    ->where('branch_id', $branch->id)
                    ->inRandomOrder()
                    ->value('id') ?? $session->opened_by;

                // Create via factory but set critical fields here
                PosCashMovementFactory::new()->make([
                    'branch_id' => $branch->id,
                    'pos_register_id' => $register->id,
                    'pos_session_id' => $session->id,
                    'created_by' => $userId,

                    'currency' => $currency,
                    'currency_rate' => $rate,

                    'direction' => $direction,
                    'reason' => $reason,
                    'amount' => $amount,

                    'balance_before' => $before,
                    'balance_after' => $after,
                    'occurred_at' => $ticks[$i],
                ])->save();
            }

            if ($session->isClosed()) {
                $systemCash = PosCashMovement::where('pos_session_id', $session->id)
                    ->selectRaw("
                        COALESCE(SUM(CASE WHEN direction = ? THEN amount ELSE 0 END),0)
                      - COALESCE(SUM(CASE WHEN direction = ? THEN amount ELSE 0 END),0) AS net_cash",
                        [PosCashDirection::In->value, PosCashDirection::Out->value]
                    )
                    ->value('net_cash');

                $declared = round($systemCash + fake()->randomFloat(2, -5, 5), 2);
                $overShort = $declared - $systemCash;

                $session->forceFill([
                    'system_cash_sales' => $systemCash,
                    'declared_cash' => $declared,
                    'cash_over_short' => $overShort,
                ])->saveQuietly();
            }
        }
    }

    /**
     * Distribute timestamps evenly with small jitter.
     */
    private function distributeTimestamps(Carbon $start, Carbon $end, int $count): array
    {
        $totalSeconds = max(1, $end->diffInSeconds($start));
        $step = max(1, (int)floor($totalSeconds / max(1, $count)));
        $times = [];
        for ($i = 0; $i < $count; $i++) {
            $base = (clone $start)->addSeconds($i * $step);
            $jitter = rand(-120, 120);
            $t = (clone $base)->addSeconds($jitter);
            if ($t->lessThan($start)) $t = clone $start;
            if ($t->greaterThan($end)) $t = clone $end;
            $times[] = $t;
        }
        sort($times);
        return $times;
    }

    /**
     * Return [direction, reason, amount] with weighted realism.
     */
    private function randomEvent(): array
    {
        // Weighted direction selection
        $roll = mt_rand(1, 100);

        if ($roll <= 60) {
            // IN — mostly sales and tip_in
            $reason = $this->weighted([
                PosCashReason::Sale->value => 80,
                PosCashReason::TipIn->value => 15,
                PosCashReason::PayIn->value => 5,
            ]);
            $amount = match ($reason) {
                PosCashReason::Sale->value => (float)fake()->randomFloat(2, 5, 70),
                PosCashReason::TipIn->value => (float)fake()->randomFloat(2, 1, 10),
                default => (float)fake()->randomFloat(2, 5, 40),
            };
            return [PosCashDirection::In->value, $reason, $amount];
        }

        if ($roll <= 90) {
            // OUT — refunds, payouts, drops, tip_out
            $reason = $this->weighted([
                PosCashReason::PayOut->value => 35,
                PosCashReason::CashDrop->value => 30,
                PosCashReason::Refund->value => 20,
                PosCashReason::TipOut->value => 15,
            ]);
            $amount = match ($reason) {
                PosCashReason::CashDrop->value => (float)fake()->randomFloat(2, 20, 120),
                PosCashReason::Refund->value => (float)fake()->randomFloat(2, 5, 50),
                PosCashReason::TipOut->value => (float)fake()->randomFloat(2, 5, 30),
                default => (float)fake()->randomFloat(2, 5, 60),
            };
            return [PosCashDirection::Out->value, $reason, $amount];
        }

        // ADJUST — rare
        $reason = fake()->randomElement([PosCashReason::ClosingAdjust->value, PosCashReason::Correction->value]);
        $amount = (float)fake()->randomFloat(2, 0, 10); // informational; won’t affect running
        return [PosCashDirection::Adjust->value, $reason, $amount];
    }

    /**
     * Weighted choice helper.
     * @param array<string,int> $options [value => weight]
     */
    private function weighted(array $options): string
    {
        $sum = array_sum($options);
        $pick = mt_rand(1, $sum);
        $acc = 0;
        foreach ($options as $value => $weight) {
            $acc += $weight;
            if ($pick <= $acc) return $value;
        }
        return array_key_first($options);
    }
}
