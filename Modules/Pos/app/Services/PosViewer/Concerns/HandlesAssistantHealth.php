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

trait HandlesAssistantHealth
{
    private function assistantPlaybookFor(string $type, int $expectedSeconds): array
    {
        $playbook = match ($type) {
            'customer_waiting' => [
                'impact' => 'Guest wait time is increasing before the first order.',
                'fix' => 'Open the table and start the order now.',
            ],
            'kitchen_ready' => [
                'impact' => 'Food quality drops while ready items wait.',
                'fix' => 'Serve the ready order before starting lower-priority work.',
            ],
            'order_delayed' => [
                'impact' => 'Kitchen SLA is breached and guest frustration risk is rising.',
                'fix' => 'Open the order, verify kitchen status, and update the guest.',
            ],
            'payment_pending' => [
                'impact' => 'Table turnover is blocked by pending payment.',
                'fix' => 'Collect payment and close the order.',
            ],
            'table_idle_after_payment' => [
                'impact' => 'A paid table is still blocked for new guests.',
                'fix' => 'Release or mark the table available.',
            ],
            'print_failed', 'print_pending' => [
                'impact' => 'Kitchen or billing documents may be delayed.',
                'fix' => 'Open the print queue and retry or use a backup printer.',
            ],
            'terminal_error', 'terminal_offline', 'terminal_syncing' => [
                'impact' => 'Offline queue or sync work may delay settlement.',
                'fix' => 'Open recovery and let the terminal retry safely.',
            ],
            'kitchen_load_increasing' => [
                'impact' => 'More orders may become delayed if kitchen pressure keeps rising.',
                'fix' => 'Open kitchen view and prioritize ready/delayed items.',
            ],
            'waiter_overloaded' => [
                'impact' => 'One waiter may slow service across multiple tables.',
                'fix' => 'Avoid adding new tables to this waiter until blockers clear.',
            ],
            'reservation_soon' => [
                'impact' => 'Reservation seating can be delayed if the table is not prepared.',
                'fix' => 'Prepare the table and keep it ready for arrival.',
            ],
            'cash_session_missing' => [
                'impact' => 'Payments, collection, and cash movement can be blocked.',
                'fix' => 'Open or resume the POS session.',
            ],
            'waiter_assignment' => [
                'impact' => 'A waiting table has no clear owner.',
                'fix' => 'Confirm the suggested waiter assignment.',
            ],
            'restaurant_health' => [
                'impact' => 'Multiple operational signals are affecting service flow.',
                'fix' => 'Clear the top blocker first, then refresh the assistant.',
            ],
            default => [
                'impact' => 'Service flow may slow down.',
                'fix' => 'Open the suggested action and resolve the blocker.',
            ],
        };

        return [
            'reason' => $this->assistantReasonFor($type),
            'impact' => $playbook['impact'],
            'fix' => $playbook['fix'],
            'eta_seconds' => $expectedSeconds,
            'eta_label' => $this->assistantExpectedTimeLabel($expectedSeconds),
        ];
    }

    private function assistantExpectedTimeLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return "~{$seconds}s";
        }

        return '~' . max(1, (int) ceil($seconds / 60)) . 'm';
    }

    private function assistantElapsedMinutes(?string $createdAt): int
    {
        if (! $createdAt) {
            return 0;
        }

        $timestamp = strtotime($createdAt);
        if (! $timestamp) {
            return 0;
        }

        return max(0, (int) floor((time() - $timestamp) / 60));
    }

    private function assistantEscalationFor(int $ignoredMinutes): array
    {
        return [
            'ignored_minutes' => $ignoredMinutes,
            'level' => match (true) {
                $ignoredMinutes >= 20 => 'critical',
                $ignoredMinutes >= 15 => 'manager_notify_due',
                $ignoredMinutes >= 10 => 'escalated',
                $ignoredMinutes >= 5 => 'increased_priority',
                default => 'normal',
            },
            'manager_notification_due' => $ignoredMinutes >= 15,
            'critical_after_minutes' => 20,
            'manager_notify_after_minutes' => 15,
        ];
    }

    private function orderLocationLabel(Order $order): string
    {
        $tableName = $order->relationLoaded('table') ? $order->table?->name : null;

        if ($tableName) {
            return "Table {$tableName}";
        }

        return 'Order #' . ($order->order_number ?: $order->id);
    }

    private function restaurantHealthScore(?int $branchId, User $user, array $cards): array
    {
        $visibleCards = collect($cards)
            ->filter(fn(array $card) => ($card['action_policy']['visible'] ?? true) !== false);
        // Single NexDine Intelligence Layer health scoring (no local formula).
        $health = HealthScore::fromCards($visibleCards->values()->all());

        return [
            ...$health->toArray(),
            'branch_id' => $branchId,
            'waiter_id' => $user->hasRole(DefaultRole::Waiter->value) ? $user->id : null,
            'drivers' => $visibleCards
                ->sortByDesc(fn(array $card) => (int) ($card['priority_score'] ?? 0))
                ->take(3)
                ->map(fn(array $card) => [
                    'type' => $card['type'] ?? 'unknown',
                    'severity' => $card['severity'] ?? 'info',
                    'title' => $card['title'] ?? 'Operational signal',
                    'message' => $card['message'] ?? null,
                ])
                ->values()
                ->all(),
            'generated_at' => now()->toISOString(),
        ];
    }

    private function assistantBottlenecks(array $cards): array
    {
        return collect($cards)
            ->filter(fn(array $card) => in_array($card['severity'] ?? '', ['critical', 'warning'], true))
            ->groupBy(fn(array $card) => $this->assistantDomainFor((string) ($card['type'] ?? 'unknown')))
            ->map(fn(Collection $items, string $domain) => [
                'domain' => $domain,
                'severity' => $items->contains(fn(array $card) => ($card['severity'] ?? '') === 'critical')
                    ? 'critical'
                    : 'warning',
                'count' => $items->count(),
                'top_issue' => $items->sortByDesc(fn(array $card) => (int) ($card['priority_score'] ?? 0))->first()['title'] ?? 'Operational issue',
                'solution' => $this->assistantDomainSolution($domain),
            ])
            ->sortByDesc(fn(array $item) => $item['severity'] === 'critical' ? 2 : 1)
            ->values()
            ->take(5)
            ->all();
    }

    private function assistantOperationTimeline(array $cards, array $health): array
    {
        $events = [[
            'id' => 'health-' . now()->format('YmdHi'),
            'type' => 'restaurant_health',
            'severity' => ($health['status'] ?? 'healthy') === 'healthy' ? 'info' : (($health['status'] ?? '') === 'critical' ? 'critical' : 'warning'),
            'title' => 'Health score ' . ($health['score'] ?? 100) . '/100',
            'message' => (string) ($health['label'] ?? 'Healthy'),
            'occurred_at' => now()->toISOString(),
        ]];

        foreach (collect($cards)->take(8) as $card) {
            $events[] = [
                'id' => $card['id'] ?? uniqid('event-', false),
                'type' => $card['type'] ?? 'operational',
                'severity' => $card['severity'] ?? 'info',
                'title' => $card['title'] ?? 'Operational signal',
                'message' => $card['message'] ?? null,
                'action' => $card['action'] ?? null,
                'occurred_at' => $card['created_at'] ?? now()->toISOString(),
            ];
        }

        return $events;
    }

    private function waiterAssignmentSuggestion(?int $branchId, User $user): ?array
    {
        if (! $branchId || ! Schema::hasTable('tables') || ! $user->can('admin.tables.assign_waiter')) {
            return null;
        }

        $table = Table::query()
            ->withOutGlobalBranchPermission()
            ->where('branch_id', $branchId)
            ->where('status', TableStatus::Occupied->value)
            ->whereDoesntHave('activeOrders')
            ->oldest('updated_at')
            ->first(['id', 'name', 'updated_at']);

        if (! $table) {
            return null;
        }

        $waiters = collect(User::list($branchId, DefaultRole::Waiter))
            ->map(fn($waiter) => [
                'id' => (int) ($waiter['id'] ?? 0),
                'name' => (string) ($waiter['name'] ?? 'Waiter'),
            ])
            ->filter(fn(array $waiter) => $waiter['id'] > 0)
            ->values();

        if ($waiters->isEmpty()) {
            return null;
        }

        $activeStatuses = [
            OrderStatus::Pending->value,
            OrderStatus::Confirmed->value,
            OrderStatus::Preparing->value,
            OrderStatus::Ready->value,
            OrderStatus::Served->value,
        ];
        $loads = $this->assistantScopedOrderQuery($branchId, $user, scopeWaiter: false)
            ->whereIn('orders.status', $activeStatuses)
            ->whereNotNull('orders.waiter_id')
            ->select('orders.waiter_id')
            ->selectRaw('COUNT(*) as active_orders')
            ->groupBy('orders.waiter_id')
            ->get()
            ->keyBy(fn($row) => (int) $row->waiter_id);

        $suggested = $waiters
            ->sortBy(fn(array $waiter) => (int) ($loads[$waiter['id']]->active_orders ?? 0))
            ->first();

        if (! $suggested) {
            return null;
        }

        return [
            'available' => true,
            'table_id' => $table->id,
            'table_name' => $table->name,
            'waiter_id' => $suggested['id'],
            'waiter_name' => $suggested['name'],
            'current_load' => (int) ($loads[$suggested['id']]->active_orders ?? 0),
            'reason' => 'Least loaded waiter for the oldest waiting occupied table.',
            'action_policy' => $this->assistantPolicy(
                allowed: true,
                loadingKey: 'assign_waiter',
                permissions: ['admin.tables.assign_waiter'],
                confirmationRequired: true,
            ),
        ];
    }

    private function assistantDomainFor(string $type): string
    {
        return match ($type) {
            'kitchen_ready', 'order_delayed', 'kitchen_load_increasing' => 'kitchen',
            'payment_pending', 'cash_session_missing' => 'payments',
            'print_failed', 'print_pending' => 'printers',
            'terminal_error', 'terminal_offline', 'terminal_syncing' => 'recovery',
            'customer_waiting', 'table_idle_after_payment', 'waiter_assignment' => 'tables',
            'waiter_overloaded' => 'waiters',
            'reservation_soon' => 'reservations',
            default => 'operations',
        };
    }

    private function assistantDomainSolution(string $domain): string
    {
        return match ($domain) {
            'kitchen' => 'Clear ready and delayed orders first.',
            'payments' => 'Close pending payments or open the POS session.',
            'printers' => 'Retry failed jobs or switch to backup printer.',
            'recovery' => 'Open recovery and let queues sync safely.',
            'tables' => 'Open the table action and release or start service.',
            'waiters' => 'Balance new work away from overloaded waiters.',
            'reservations' => 'Prepare the reserved table before arrival.',
            default => 'Resolve the top priority card first.',
        };
    }

    private function restaurantHealthCard(array $health, User $user): array
    {
        $status = (string) ($health['status'] ?? 'attention_required');
        $severity = $status === 'critical' ? 'critical' : 'warning';
        $drivers = collect($health['drivers'] ?? []);
        $topDriver = $drivers->first() ?: [];
        $topDriverType = (string) ($topDriver['type'] ?? '');
        $action = str_contains($topDriverType, 'terminal') || str_contains($topDriverType, 'print')
            ? 'open_recovery_dashboard'
            : 'open_order';

        return $this->assistantCard(
            id: 'restaurant-health-' . now()->format('YmdHi'),
            type: 'restaurant_health',
            severity: $severity,
            title: $status === 'critical' ? 'Restaurant flow critical' : 'Restaurant needs attention',
            message: "Health score {$health['score']}/100 from {$health['critical_count']} critical and {$health['warning_count']} warning signal(s).",
            entityType: 'restaurant_health',
            entityId: null,
            action: $action,
            policy: $this->assistantPolicy(
                allowed: $user->can('admin.pos.index') || $user->can('admin.orders.active') || $user->can('admin.pos_terminal_devices.index'),
                reason: 'Permission denied.',
                loadingKey: 'restaurant_health',
                permissions: ['admin.pos.index', 'admin.orders.active', 'admin.pos_terminal_devices.index']
            ),
            currentStatus: (string) ($health['label'] ?? 'Needs attention'),
        );
    }

}
