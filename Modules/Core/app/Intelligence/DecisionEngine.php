<?php

namespace Modules\Core\Intelligence;

/**
 * NexDine Intelligence Layer — the ONE deterministic decision engine.
 *
 * Single source of operational priority scoring for every intelligent feature
 * (Waiter Assistant, Copilot, Operational Intelligence, …). No module may keep
 * its own scoring table. Fully deterministic and explainable — no LLM.
 *
 * The priority formula is intentionally identical to the legacy assistant
 * scoring so existing card ordering is preserved; the impact/confidence
 * dimensions are added for the standardized recommendation contract.
 */
final class DecisionEngine
{
    /**
     * Score a single operational signal type.
     *
     * @param string $type            Signal type (e.g. 'payment_pending').
     * @param int    $ignoredMinutes  How long the signal has gone unattended.
     */
    public function score(string $type, int $ignoredMinutes = 0): DecisionScore
    {
        $baseScore = match ($type) {
            'customer_waiting' => 100,
            'cash_session_missing' => 95,
            'payment_pending' => 90,
            'table_idle_after_payment' => 88,
            'kitchen_load_increasing' => 86,
            'order_delayed' => 85,
            'restaurant_health' => 82,
            'kitchen_ready' => 80,
            'waiter_overloaded' => 78,
            'reservation_soon' => 70,
            'print_failed' => 60,
            'terminal_error', 'terminal_offline' => 55,
            'print_pending', 'terminal_syncing' => 50,
            default => 20,
        };

        $score = min(120, $baseScore + match (true) {
            $ignoredMinutes >= 20 => 30,
            $ignoredMinutes >= 15 => 20,
            $ignoredMinutes >= 10 => 12,
            $ignoredMinutes >= 5 => 6,
            default => 0,
        });

        return new DecisionScore(
            type: $type,
            priority: $score,
            urgency: match (true) {
                $score >= 90 => 'urgent',
                $score >= 70 => 'high',
                $score >= 50 => 'medium',
                default => 'low',
            },
            confidence: $this->confidenceFor($type),
            businessImpact: $this->impactFor($type)['business'],
            customerImpact: $this->impactFor($type)['customer'],
            revenueImpact: $this->impactFor($type)['revenue'],
        );
    }

    /**
     * Deterministic confidence per signal: hard system facts (failed print,
     * pending payment) are near-certain; inferred pressure signals are lower.
     */
    private function confidenceFor(string $type): float
    {
        return match ($type) {
            'payment_pending',
            'cash_session_missing',
            'print_failed',
            'print_pending',
            'terminal_error',
            'terminal_offline',
            'terminal_syncing',
            'kitchen_ready',
            'table_idle_after_payment' => 0.98,
            'order_delayed',
            'reservation_soon',
            'customer_waiting' => 0.9,
            'kitchen_load_increasing',
            'waiter_overloaded',
            'restaurant_health' => 0.8,
            default => 0.7,
        };
    }

    /**
     * Qualitative business/customer/revenue impact per signal.
     *
     * @return array{business:string,customer:string,revenue:string}
     */
    private function impactFor(string $type): array
    {
        return match ($type) {
            'customer_waiting' => ['business' => 'high', 'customer' => 'high', 'revenue' => 'high'],
            'payment_pending' => ['business' => 'high', 'customer' => 'medium', 'revenue' => 'high'],
            'table_idle_after_payment' => ['business' => 'high', 'customer' => 'medium', 'revenue' => 'high'],
            'cash_session_missing' => ['business' => 'high', 'customer' => 'low', 'revenue' => 'high'],
            'order_delayed' => ['business' => 'high', 'customer' => 'high', 'revenue' => 'medium'],
            'kitchen_ready' => ['business' => 'medium', 'customer' => 'high', 'revenue' => 'low'],
            'kitchen_load_increasing' => ['business' => 'high', 'customer' => 'medium', 'revenue' => 'medium'],
            'waiter_overloaded' => ['business' => 'medium', 'customer' => 'medium', 'revenue' => 'low'],
            'reservation_soon' => ['business' => 'medium', 'customer' => 'high', 'revenue' => 'medium'],
            'print_failed' => ['business' => 'high', 'customer' => 'medium', 'revenue' => 'medium'],
            'print_pending' => ['business' => 'medium', 'customer' => 'low', 'revenue' => 'low'],
            'terminal_error', 'terminal_offline', 'terminal_syncing' => ['business' => 'high', 'customer' => 'low', 'revenue' => 'medium'],
            'restaurant_health' => ['business' => 'high', 'customer' => 'medium', 'revenue' => 'medium'],
            default => ['business' => 'low', 'customer' => 'low', 'revenue' => 'low'],
        };
    }
}
