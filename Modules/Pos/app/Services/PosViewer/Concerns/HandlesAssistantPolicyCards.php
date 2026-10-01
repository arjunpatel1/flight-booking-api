<?php

namespace Modules\Pos\Services\PosViewer\Concerns;

use Darryldecode\Cart\Exceptions\InvalidConditionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\Cart\Facades\Cart;
use Modules\Category\Models\Category;
use Modules\Discount\Models\Discount;
use Modules\Menu\Models\Menu;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Order\Support\OrderActionPolicy;
use Modules\Pos\Enums\PosCashDirection;
use Modules\Pos\Enums\PosCashReason;
use Modules\Pos\Enums\PosSessionStatus;
use Modules\Pos\Models\PosRegister;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Models\PosTerminalDevice;
use Modules\Pos\Transformers\Api\V1\Pos\PosCategoryResource;
use Modules\Pos\Transformers\Api\V1\Pos\PosProductResource;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Models\PrintJob;
use Modules\Product\Models\Product;
use Modules\SeatingPlan\Enums\ReservationStatus;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\TableReservation;
use Modules\Support\ActionPolicy;
use Modules\Support\Money;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;
use Modules\Core\Intelligence\ContextEngine;
use Modules\Core\Intelligence\DecisionEngine;
use Modules\Core\Intelligence\HealthScore;
use Modules\Core\Intelligence\MemoryScore;
use Modules\Core\Intelligence\PredictionEngine;

trait HandlesAssistantPolicyCards
{
    private function assistantOrderQuery(?int $branchId, User $user): Builder
    {
        return $this->assistantScopedOrderQuery($branchId, $user)
            ->with(['table:id,name', 'waiter:id,name']);
    }

    private function assistantScopedOrderQuery(?int $branchId, User $user, bool $scopeWaiter = true): Builder
    {
        return Order::query()
            ->withOutGlobalBranchPermission()
            ->when($branchId, fn(Builder $query) => $query->where('orders.branch_id', $branchId))
            ->when(
                $scopeWaiter && $user->hasRole(DefaultRole::Waiter->value),
                fn(Builder $query) => $query->where('orders.waiter_id', $user->id)
            )
            ->whereDate('orders.order_date', today())
            ->whereNotIn('orders.status', [
                OrderStatus::Cancelled->value,
                OrderStatus::Refunded->value,
                OrderStatus::Merged->value,
            ]);
    }

    private function orderCard(
        Order $order,
        User $user,
        string $type,
        string $severity,
        string $title,
        string $message,
        string $action,
        string $actionName,
    ): array {
        $policy = OrderActionPolicy::make($order, $user)[$actionName] ?? $this->assistantPolicy(true);

        return $this->assistantCard(
            id: "{$type}-{$order->id}",
            type: $type,
            severity: $severity,
            title: $title,
            message: $message,
            entityType: 'order',
            entityId: (string) $order->id,
            tableId: $order->table_id,
            orderId: $order->id,
            action: $action,
            policy: $policy,
            createdAt: $order->updated_at?->toISOString() ?: $order->created_at?->toISOString(),
            expiresAt: now()->addMinutes(20)->toISOString(),
            currentStatus: $order->status->trans(),
            businessImpact: $this->assistantOrderBusinessImpact($type, $order),
        );
    }

    private function assistantCard(
        string $id,
        string $type,
        string $severity,
        string $title,
        string $message,
        string $entityType,
        ?string $entityId,
        ?int $tableId = null,
        ?int $orderId = null,
        ?string $action = null,
        ?array $policy = null,
        ?string $createdAt = null,
        ?string $expiresAt = null,
        ?string $currentStatus = null,
        ?array $businessImpact = null,
    ): array {
        $createdAt ??= now()->toISOString();
        $ignoredMinutes = $this->assistantElapsedMinutes($createdAt);
        // Single NexDine Intelligence Layer decision — drives priority AND the
        // canonical recommendation fields (confidence + impact) for this card.
        $score = (new DecisionEngine())->score($type, $ignoredMinutes);
        $expectedSeconds = $this->assistantExpectedSecondsFor($type);
        $businessImpact ??= $this->assistantBusinessImpact($type, $ignoredMinutes, $score);

        return [
            'id' => $id,
            'dismiss_token' => md5(implode('|', [$id, $type, $currentStatus ?? $this->assistantCurrentStatusFor($type), $createdAt])),
            'type' => $type,
            'priority' => $score->urgency,
            'priority_score' => $score->priority,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'reason' => $this->assistantReasonFor($type),
            // ── Canonical NIL recommendation contract (additive) ──
            'confidence' => $score->confidence,
            'business_impact' => $businessImpact,
            'explanation' => $this->assistantReasonFor($type),
            'tracking_id' => md5('rec|' . $id . '|' . $type . '|' . $createdAt),
            'current_status' => $currentStatus ?? $this->assistantCurrentStatusFor($type),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'table_id' => $tableId,
            'order_id' => $orderId,
            'action' => $action,
            'action_label' => $this->assistantActionLabelFor($action, $type),
            'action_chain' => $this->assistantActionChainFor($type),
            'expected_time_seconds' => $expectedSeconds,
            'expected_time_label' => $this->assistantExpectedTimeLabel($expectedSeconds),
            'ignored_minutes' => $ignoredMinutes,
            'escalation' => $this->assistantEscalationFor($ignoredMinutes),
            'playbook' => $this->assistantPlaybookFor($type, $expectedSeconds),
            'is_next_action' => false,
            'autopilot_rank_reason' => null,
            'action_policy' => $policy ?? $this->assistantPolicy(true),
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
        ];
    }

    private function sortAssistantCards(array $cards): Collection
    {
        $severityRank = ['critical' => 0, 'warning' => 1, 'info' => 2];

        return collect($cards)
            ->sortBy([
                fn(array $a, array $b) => ((int) ($b['priority_score'] ?? 0)) <=> ((int) ($a['priority_score'] ?? 0)),
                fn(array $a, array $b) => ($severityRank[$a['severity']] ?? 9) <=> ($severityRank[$b['severity']] ?? 9),
                fn(array $a, array $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')),
            ])
            ->values();
    }

    private function assistantNextAction(Collection $sortedCards): ?array
    {
        $card = $sortedCards
            ->first(fn(array $card) => ($card['action_policy']['visible'] ?? true) !== false && ! empty($card['action']));

        if (! $card) {
            return null;
        }

        return [
            ...$card,
            'is_next_action' => true,
            'autopilot_rank_reason' => $this->assistantNextActionReason($card),
        ];
    }

    private function assistantNextActionReason(array $card): string
    {
        $priority = (string) ($card['priority'] ?? 'low');
        $ignored = (int) ($card['ignored_minutes'] ?? 0);
        $reason = (string) ($card['reason'] ?? 'Highest operational priority right now.');

        return $ignored > 0
            ? "{$reason} Priority is {$priority}; ignored for {$ignored} minute(s)."
            : "{$reason} Priority is {$priority}.";
    }

    private function assistantPolicy(
        bool $allowed,
        ?string $reason = null,
        bool $visible = true,
        bool $managerApprovalRequired = false,
        ?string $loadingKey = null,
        array|string $permissions = [],
        bool $confirmationRequired = false,
    ): array {
        return ActionPolicy::make(
            allowed: $allowed,
            reason: $reason,
            visible: $visible,
            managerApprovalRequired: $managerApprovalRequired,
            loadingKey: $loadingKey,
            permissions: $permissions,
            confirmationRequired: $confirmationRequired,
        );
    }

    private function assistantBusinessImpact(string $type, int $ignoredMinutes, mixed $score): array
    {
        return [
            'business' => $score->businessImpact,
            'customer' => $score->customerImpact,
            'revenue' => $score->revenueImpact,
            'impact_type' => $this->assistantImpactTypeFor($type),
            'estimated_loss' => $this->assistantMoneyPayload(null),
            'time_lost_minutes' => max(0, $ignoredMinutes),
            'priority_score' => $score->priority,
            'recommended_action' => $this->assistantRecommendedActionFor($type),
        ];
    }

    private function assistantOrderBusinessImpact(string $type, Order $order): array
    {
        $createdAt = $order->updated_at?->toISOString() ?: $order->created_at?->toISOString();
        $ignoredMinutes = $this->assistantElapsedMinutes($createdAt);
        $score = (new DecisionEngine())->score($type, $ignoredMinutes);
        $impact = $this->assistantBusinessImpact($type, $ignoredMinutes, $score);

        $estimate = match ($type) {
            'payment_pending' => $order->due_amount,
            'table_idle_after_payment' => $this->assistantEstimatedOrderLoss($order, 0.05),
            'order_delayed' => $this->assistantEstimatedOrderLoss($order, 0.03),
            'kitchen_ready' => $this->assistantEstimatedOrderLoss($order, 0.01),
            default => null,
        };

        return [
            ...$impact,
            'estimated_loss' => $this->assistantMoneyPayload($estimate),
        ];
    }

    private function assistantEstimatedOrderLoss(Order $order, float $ratio): Money
    {
        return new Money(max(0, $order->total->amount() * $ratio), setting('default_currency') ?: 'INR');
    }

    private function assistantMoneyPayload(mixed $value): ?array
    {
        if ($value instanceof Money) {
            return $value->round()->toArray();
        }

        if (is_numeric($value)) {
            return (new Money((float) $value, setting('default_currency') ?: 'INR'))->round()->toArray();
        }

        return null;
    }

    private function assistantImpactTypeFor(string $type): string
    {
        return match ($type) {
            'payment_pending', 'table_idle_after_payment' => 'revenue_leak',
            'customer_waiting', 'kitchen_ready', 'order_delayed', 'reservation_soon' => 'service_delay',
            'print_failed', 'print_pending', 'terminal_error', 'terminal_offline', 'terminal_syncing' => 'recovery_risk',
            'cash_session_missing' => 'session_blocker',
            'kitchen_load_increasing', 'waiter_overloaded', 'restaurant_health' => 'operational_bottleneck',
            'waiter_assignment' => 'labor_balance',
            default => 'operational_attention',
        };
    }

    private function assistantRecommendedActionFor(string $type): string
    {
        return match ($type) {
            'customer_waiting' => 'Open the table and start the order.',
            'payment_pending' => 'Collect payment and close the bill.',
            'table_idle_after_payment' => 'Release the table for the next guest.',
            'kitchen_ready' => 'Serve the ready order.',
            'order_delayed' => 'Open the order and check kitchen status.',
            'kitchen_load_increasing' => 'Open kitchen view and clear delayed items first.',
            'waiter_overloaded' => 'Review active orders and avoid assigning more work to the busiest waiter.',
            'restaurant_health' => 'Open operational risks and clear blockers.',
            'reservation_soon' => 'Prepare or open the reservation.',
            'print_failed' => 'Retry the failed print after checking the printer.',
            'print_pending' => 'Open the print queue and verify agent/printer health.',
            'terminal_error', 'terminal_offline', 'terminal_syncing' => 'Open recovery dashboard and clear sync/device issues.',
            'cash_session_missing' => 'Open the POS session before collecting cash or payments.',
            'waiter_assignment' => 'Review the waiter assignment suggestion.',
            default => 'Open the item and take the next safe action.',
        };
    }

    private function assistantReasonFor(string $type): string
    {
        return match ($type) {
            'customer_waiting' => 'Guests are waiting and no order is open.',
            'cash_session_missing' => 'Cash and payment actions can be blocked without an open POS session.',
            'payment_pending' => 'Closing payment frees the table faster.',
            'table_idle_after_payment' => 'Paid tables should be released quickly for the next guest.',
            'kitchen_load_increasing' => 'Kitchen pressure is rising and delays may spread to more orders.',
            'order_delayed' => 'Kitchen SLA has been exceeded.',
            'restaurant_health' => 'Multiple operational signals need attention.',
            'kitchen_ready' => 'Food is ready and should reach the table quickly.',
            'waiter_overloaded' => 'Workload is uneven and service speed may drop.',
            'reservation_soon' => 'Reserved guests are arriving soon.',
            'print_failed' => 'A failed print can delay kitchen or billing flow.',
            'print_pending' => 'Print queue is waiting longer than expected.',
            'terminal_error', 'terminal_offline', 'terminal_syncing' => 'This terminal has recovery work pending.',
            'waiter_assignment' => 'A waiting table should be assigned to the least loaded waiter.',
            default => 'Operational attention required.',
        };
    }

    private function assistantCurrentStatusFor(string $type): string
    {
        return match ($type) {
            'customer_waiting' => 'Waiting for order',
            'cash_session_missing' => 'Session required',
            'payment_pending' => 'Payment pending',
            'table_idle_after_payment' => 'Ready to release',
            'kitchen_load_increasing' => 'Kitchen pressure rising',
            'order_delayed' => 'Delayed',
            'restaurant_health' => 'Needs attention',
            'kitchen_ready' => 'Ready for service',
            'waiter_overloaded' => 'Workload high',
            'reservation_soon' => 'Arriving soon',
            'print_failed' => 'Print failed',
            'print_pending' => 'Print pending',
            'terminal_error' => 'Terminal error',
            'terminal_offline' => 'Terminal offline',
            'terminal_syncing' => 'Sync pending',
            'waiter_assignment' => 'Assignment suggested',
            default => 'Needs attention',
        };
    }

    private function assistantActionLabelFor(?string $action, string $type): string
    {
        return match ($action) {
            'open_table' => 'Open Table',
            'receive_payment' => 'Open Payment',
            'retry_print' => 'Retry Print',
            'open_print_queue' => 'Open Queue',
            'open_recovery_dashboard' => 'Sync Now',
            'open_reservation' => 'Open Reservation',
            'open_pos_session' => 'Open Session',
            'open_kitchen' => 'Open Kitchen',
            'review_assignment' => 'Review Assignment',
            'open_order' => $type === 'kitchen_ready' ? 'Open Ready Order' : 'Open Order',
            default => 'Open',
        };
    }

    private function assistantActionChainFor(string $type): array
    {
        $steps = match ($type) {
            'customer_waiting' => [
                ['key' => 'take_order', 'label' => 'Take Order', 'action' => 'open_table'],
                ['key' => 'send_kot', 'label' => 'Send KOT', 'action' => 'open_order'],
                ['key' => 'serve_food', 'label' => 'Serve Food', 'action' => 'open_order'],
                ['key' => 'collect_payment', 'label' => 'Collect Payment', 'action' => 'receive_payment'],
            ],
            'kitchen_ready' => [
                ['key' => 'serve_food', 'label' => 'Serve Food', 'action' => 'open_order'],
                ['key' => 'collect_payment', 'label' => 'Collect Payment', 'action' => 'receive_payment'],
                ['key' => 'print_bill', 'label' => 'Print Bill', 'action' => 'open_print_queue'],
                ['key' => 'mark_available', 'label' => 'Mark Available', 'action' => 'open_table'],
            ],
            'payment_pending' => [
                ['key' => 'collect_payment', 'label' => 'Collect Payment', 'action' => 'receive_payment'],
                ['key' => 'print_bill', 'label' => 'Print Bill', 'action' => 'open_print_queue'],
                ['key' => 'mark_available', 'label' => 'Mark Available', 'action' => 'open_table'],
            ],
            'table_idle_after_payment' => [
                ['key' => 'release_table', 'label' => 'Release Table', 'action' => 'open_table'],
                ['key' => 'seat_next_guest', 'label' => 'Seat Next Guest', 'action' => 'open_table'],
            ],
            'kitchen_load_increasing' => [
                ['key' => 'open_kitchen', 'label' => 'Open Kitchen', 'action' => 'open_kitchen'],
                ['key' => 'prioritize_ready', 'label' => 'Prioritize Ready', 'action' => 'open_order'],
                ['key' => 'notify_waiter', 'label' => 'Notify Waiter', 'action' => 'open_order'],
            ],
            'waiter_overloaded' => [
                ['key' => 'review_orders', 'label' => 'Review Orders', 'action' => 'open_order'],
                ['key' => 'collect_payments', 'label' => 'Collect Payments', 'action' => 'receive_payment'],
                ['key' => 'avoid_new_assignment', 'label' => 'Avoid New Table', 'action' => 'open_table'],
            ],
            'restaurant_health' => [
                ['key' => 'review_risks', 'label' => 'Review Risks', 'action' => 'open_order'],
                ['key' => 'clear_blockers', 'label' => 'Clear Blockers', 'action' => 'open_recovery_dashboard'],
            ],
            'reservation_soon' => [
                ['key' => 'prepare_table', 'label' => 'Prepare Table', 'action' => 'open_reservation'],
                ['key' => 'seat_guest', 'label' => 'Seat Guest', 'action' => 'open_reservation'],
                ['key' => 'take_order', 'label' => 'Take Order', 'action' => 'open_table'],
            ],
            'print_failed', 'print_pending' => [
                ['key' => 'retry_print', 'label' => 'Retry Print', 'action' => 'retry_print'],
                ['key' => 'verify_printer', 'label' => 'Verify Printer', 'action' => 'open_print_queue'],
            ],
            'terminal_error', 'terminal_offline', 'terminal_syncing' => [
                ['key' => 'retry_sync', 'label' => 'Retry Sync', 'action' => 'open_recovery_dashboard'],
                ['key' => 'verify_queue', 'label' => 'Verify Queue', 'action' => 'open_recovery_dashboard'],
            ],
            'cash_session_missing' => [
                ['key' => 'open_session', 'label' => 'Open Session', 'action' => 'open_pos_session'],
                ['key' => 'resume_payments', 'label' => 'Resume Payments', 'action' => 'receive_payment'],
            ],
            'waiter_assignment' => [
                ['key' => 'review_assignment', 'label' => 'Review Assignment', 'action' => 'review_assignment'],
                ['key' => 'assign_waiter', 'label' => 'Assign Waiter', 'action' => 'open_table'],
                ['key' => 'take_order', 'label' => 'Take Order', 'action' => 'open_table'],
            ],
            default => [
                ['key' => 'open', 'label' => 'Open', 'action' => 'open_order'],
            ],
        };

        return collect($steps)
            ->values()
            ->map(fn(array $step, int $index) => [
                ...$step,
                'position' => $index + 1,
                'state' => $index === 0 ? 'current' : 'upcoming',
            ])
            ->all();
    }

    private function assistantExpectedSecondsFor(string $type): int
    {
        return match ($type) {
            'customer_waiting' => 20,
            'kitchen_ready' => 20,
            'table_idle_after_payment' => 20,
            'reservation_soon' => 30,
            'waiter_overloaded' => 30,
            'restaurant_health' => 30,
            'payment_pending' => 45,
            'cash_session_missing' => 45,
            'print_failed', 'print_pending' => 60,
            'kitchen_load_increasing' => 60,
            'order_delayed' => 60,
            'terminal_error', 'terminal_offline', 'terminal_syncing' => 75,
            'waiter_assignment' => 45,
            default => 30,
        };
    }

}
