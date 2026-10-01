<?php

namespace Modules\Order\Support;

use Carbon\CarbonInterface;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;

class KitchenSla
{
    public function thresholdMinutes(): int
    {
        $minutes = setting('kitchen_delayed_order_minutes');
        if (blank($minutes)) {
            $minutes = setting('pos_delayed_order_alert_minutes');
        }

        return max((int) ($minutes ?: 25), 1);
    }

    public function delayedCutoff(): CarbonInterface
    {
        return now()->subMinutes($this->thresholdMinutes());
    }

    public function activeStatusValues(): array
    {
        return array_map(
            fn(OrderStatus $status) => $status->value,
            $this->activeStatuses()
        );
    }

    public function activeStatuses(): array
    {
        return [
            OrderStatus::Pending,
            OrderStatus::Confirmed,
            OrderStatus::Preparing,
        ];
    }

    public function estimatedCompletion(Order $order): ?CarbonInterface
    {
        if (in_array($order->status, [
            OrderStatus::Completed,
            OrderStatus::Served,
            OrderStatus::Cancelled,
            OrderStatus::Refunded,
        ], true)) {
            return null;
        }

        return $order->created_at?->copy()->addMinutes($this->thresholdMinutes());
    }

    public function payload(Order $order): array
    {
        $threshold = $this->thresholdMinutes();
        $dueAt = $order->created_at?->copy()->addMinutes($threshold);
        $elapsedMinutes = $order->created_at
            ? max(0, (int) floor($order->created_at->diffInMinutes(now())))
            : 0;
        $isDelayed = $this->isActive($order) && $dueAt?->isPast();
        $delayMinutes = $isDelayed && $dueAt
            ? max(0, (int) floor($dueAt->diffInMinutes(now())))
            : 0;

        return [
            'threshold_minutes' => $threshold,
            'due_at' => $dueAt?->toISOString(),
            'elapsed_minutes' => $elapsedMinutes,
            'delay_minutes' => $delayMinutes,
            'is_delayed' => (bool) $isDelayed,
            'severity' => $this->severity($delayMinutes),
            'escalation_level' => $this->escalationLevel($delayMinutes),
            'recommendation' => $this->recommendation($delayMinutes),
            'manager_escalation_at' => $dueAt?->copy()->addMinutes(10)->toISOString(),
        ];
    }

    private function isActive(Order $order): bool
    {
        return in_array($order->status, $this->activeStatuses(), true);
    }

    private function severity(int $delayMinutes): string
    {
        return match (true) {
            $delayMinutes >= 10 => 'critical',
            $delayMinutes > 0 => 'warning',
            default => 'normal',
        };
    }

    private function escalationLevel(int $delayMinutes): ?string
    {
        return match (true) {
            $delayMinutes >= 10 => 'manager',
            $delayMinutes >= 5 => 'captain',
            $delayMinutes > 0 => 'waiter',
            default => null,
        };
    }

    private function recommendation(int $delayMinutes): ?string
    {
        return match (true) {
            $delayMinutes >= 10 => 'Escalate to manager and prioritize this order immediately.',
            $delayMinutes >= 5 => 'Ask captain or kitchen lead to prioritize this order.',
            $delayMinutes > 0 => 'Prioritize this order before newer kitchen work.',
            default => null,
        };
    }
}
