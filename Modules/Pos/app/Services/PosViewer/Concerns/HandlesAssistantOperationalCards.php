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
use Modules\Order\Support\KitchenSla;
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

trait HandlesAssistantOperationalCards
{
    private function waitingTableCards(?int $branchId, User $user): array
    {
        if (! Schema::hasTable('tables')) {
            return [];
        }

        return Table::query()
            ->withOutGlobalBranchPermission()
            ->when($branchId, fn(Builder $query) => $query->where('branch_id', $branchId))
            ->when(
                $user->hasRole(DefaultRole::Waiter->value),
                fn(Builder $query) => $query->where('assigned_waiter_id', $user->id)
            )
            ->where('status', TableStatus::Occupied->value)
            ->whereDoesntHave('activeOrders')
            ->latest('updated_at')
            ->limit(4)
            ->get()
            ->map(fn(Table $table) => $this->assistantCard(
                id: "table-waiting-{$table->id}",
                type: 'customer_waiting',
                severity: 'critical',
                title: "Table {$table->name} waiting",
                message: 'Guests are seated but no order has been started.',
                entityType: 'table',
                entityId: (string) $table->id,
                tableId: $table->id,
                action: 'open_table',
                policy: $this->assistantPolicy(
                    allowed: $user->can('admin.pos.index') || $user->can('admin.orders.create'),
                    reason: 'Permission denied.',
                    loadingKey: 'open_table',
                    permissions: ['admin.pos.index', 'admin.orders.create']
                ),
                createdAt: $table->updated_at?->toISOString(),
                expiresAt: now()->addMinutes(15)->toISOString(),
            ))
            ->all();
    }

    /**
     * Paid-but-still-occupied orders (idle ≥3 min). Single source reused by the
     * "table can be released" card AND the table-release prediction so the query
     * runs once per assistant request.
     */
    private function tableIdleAfterPaymentOrders(?int $branchId, User $user): EloquentCollection
    {
        if (! Schema::hasTable('tables')) {
            return new EloquentCollection();
        }

        return $this->assistantOrderQuery($branchId, $user)
            ->whereNotNull('orders.table_id')
            ->where('orders.payment_status', OrderPaymentStatus::Paid->value)
            ->whereIn('orders.status', [
                OrderStatus::Served->value,
                OrderStatus::Completed->value,
            ])
            ->where('orders.updated_at', '<=', now()->subMinutes(3))
            ->whereHas(
                'table',
                fn(Builder $query) => $query->where('status', TableStatus::Occupied->value)
            )
            ->latest('orders.updated_at')
            ->limit(4)
            ->get();
    }

    /**
     * @param EloquentCollection<int,Order>|null $orders Pre-fetched idle orders.
     */
    private function tableIdleAfterPaymentCards(?int $branchId, User $user, ?EloquentCollection $orders = null): array
    {
        $orders ??= $this->tableIdleAfterPaymentOrders($branchId, $user);

        return $orders
            ->map(fn(Order $order) => $this->assistantCard(
                id: "table-idle-after-payment-{$order->id}",
                type: 'table_idle_after_payment',
                severity: 'warning',
                title: 'Table can be released',
                message: $this->orderLocationLabel($order) . ' is paid but still occupied.',
                entityType: 'order',
                entityId: (string) $order->id,
                tableId: $order->table_id,
                orderId: $order->id,
                action: 'open_table',
                policy: $this->assistantPolicy(
                    allowed: $user->can('admin.tables.update_status') || $user->can('admin.pos.index'),
                    reason: 'Permission denied.',
                    loadingKey: 'mark_available',
                    permissions: ['admin.tables.update_status', 'admin.pos.index']
                ),
                createdAt: $order->updated_at?->toISOString() ?: $order->created_at?->toISOString(),
                expiresAt: now()->addMinutes(15)->toISOString(),
                currentStatus: 'Ready to release',
            ))
            ->all();
    }

    /**
     * Live kitchen load counts (active + delayed orders). Single source reused by
     * the kitchen-load card AND the kitchen-delay prediction so the query runs once.
     *
     * @return array{active:int,delayed:int}
     */
    private function kitchenLoadCounts(?int $branchId, User $user): array
    {
        $sla = app(KitchenSla::class);
        $stats = $this->assistantScopedOrderQuery($branchId, $user)
            ->whereIn('orders.status', $sla->activeStatusValues())
            ->selectRaw('COUNT(*) as active_orders')
            ->selectRaw('SUM(CASE WHEN orders.created_at <= ? THEN 1 ELSE 0 END) as delayed_orders', [
                $sla->delayedCutoff(),
            ])
            ->first();

        return [
            'active' => (int) ($stats?->active_orders ?? 0),
            'delayed' => (int) ($stats?->delayed_orders ?? 0),
        ];
    }

    /**
     * @param array{active:int,delayed:int}|null $counts Pre-computed load counts.
     */
    private function kitchenLoadCards(?int $branchId, User $user, ?array $counts = null): array
    {
        $counts ??= $this->kitchenLoadCounts($branchId, $user);
        $activeOrders = $counts['active'];
        $delayedOrders = $counts['delayed'];

        if ($activeOrders < 8 && $delayedOrders < 3) {
            return [];
        }

        $severity = $delayedOrders >= 5 || $activeOrders >= 15 ? 'critical' : 'warning';

        return [$this->assistantCard(
            id: "kitchen-load-{$branchId}-" . now()->format('YmdHi'),
            type: 'kitchen_load_increasing',
            severity: $severity,
            title: 'Kitchen load increasing',
            message: "{$activeOrders} order(s) are active in kitchen; {$delayedOrders} are delayed.",
            entityType: 'kitchen',
            entityId: $branchId ? (string) $branchId : null,
            action: 'open_kitchen',
            policy: $this->assistantPolicy(
                allowed: $user->can('admin.pos.kitchen_viewer') || $user->can('admin.orders.active') || $user->can('admin.pos.index'),
                reason: 'Permission denied.',
                loadingKey: 'open_kitchen',
                permissions: ['admin.pos.kitchen_viewer', 'admin.orders.active', 'admin.pos.index']
            ),
            currentStatus: $delayedOrders > 0 ? "{$delayedOrders} delayed" : "{$activeOrders} active",
        )];
    }

    private function waiterLoadBalanceCards(?int $branchId, User $user): array
    {
        $activeStatuses = [
            OrderStatus::Pending->value,
            OrderStatus::Confirmed->value,
            OrderStatus::Preparing->value,
            OrderStatus::Ready->value,
            OrderStatus::Served->value,
        ];
        $sla = app(KitchenSla::class);

        $loads = $this->assistantScopedOrderQuery($branchId, $user, scopeWaiter: false)
            ->whereNotNull('orders.waiter_id')
            ->whereIn('orders.status', $activeStatuses)
            ->select('orders.waiter_id')
            ->selectRaw('COUNT(*) as active_orders')
            ->selectRaw('SUM(CASE WHEN orders.payment_status IN (?, ?) THEN 1 ELSE 0 END) as payment_pending', [
                OrderPaymentStatus::Unpaid->value,
                OrderPaymentStatus::PartiallyPaid->value,
            ])
            ->selectRaw('SUM(CASE WHEN orders.status = ? THEN 1 ELSE 0 END) as ready_orders', [
                OrderStatus::Ready->value,
            ])
            ->selectRaw('SUM(CASE WHEN orders.created_at <= ? THEN 1 ELSE 0 END) as delayed_orders', [
                $sla->delayedCutoff(),
            ])
            ->groupBy('orders.waiter_id')
            ->orderByDesc('active_orders')
            ->limit(5)
            ->get();

        if ($loads->count() < 2) {
            return [];
        }

        $load = $loads->first();
        if (! $load || (int) $load->active_orders < 6) {
            return [];
        }

        if ($user->hasRole(DefaultRole::Waiter->value) && (int) $load->waiter_id !== (int) $user->id) {
            return [];
        }

        $waiter = User::query()
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->whereKey($load->waiter_id)
            ->first(['id', 'name']);
        $waiterName = $waiter?->name ?: 'A waiter';
        $activeOrders = (int) $load->active_orders;
        $paymentPending = (int) $load->payment_pending;
        $readyOrders = (int) $load->ready_orders;
        $delayedOrders = (int) $load->delayed_orders;
        $severity = $activeOrders >= 10 || $delayedOrders >= 3 ? 'critical' : 'warning';

        return [$this->assistantCard(
            id: "waiter-load-{$load->waiter_id}-" . now()->format('YmdHi'),
            type: 'waiter_overloaded',
            severity: $severity,
            title: 'Waiter workload high',
            message: "{$waiterName} has {$activeOrders} active order(s), {$paymentPending} payment(s), {$readyOrders} pickup(s).",
            entityType: 'waiter',
            entityId: (string) $load->waiter_id,
            action: 'open_order',
            policy: $this->assistantPolicy(
                allowed: $user->can('admin.orders.active') || $user->can('admin.pos.index'),
                reason: 'Permission denied.',
                loadingKey: 'open_orders',
                permissions: ['admin.orders.active', 'admin.pos.index']
            ),
            currentStatus: $delayedOrders > 0 ? "{$delayedOrders} delayed" : "{$activeOrders} active",
        )];
    }

}
