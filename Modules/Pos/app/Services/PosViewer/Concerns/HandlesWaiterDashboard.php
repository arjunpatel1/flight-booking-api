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

trait HandlesWaiterDashboard
{
    /** @inheritDoc */
    public function waiterDashboard(?int $branchId = null): array
    {
        $user = auth()->user();
        $branchId = $user->assignedToBranch() ? $user->branch_id : $branchId;
        $cacheKey = makeCacheKey([
            'waiter-dashboard',
            'branch-' . ($branchId ?? 'all'),
            'user-' . $user->id,
            'role-' . ($user->hasRole(DefaultRole::Waiter->value) ? 'waiter' : 'admin'),
            'date-' . today()->toDateString(),
        ]);

        return $this->cacheWithTags(['orders', 'waiter-dashboard'])
            ->remember(
                $cacheKey,
                now()->addMinutes(5),
                function () use ($branchId, $user) {
                    $currency = $user->assignedToBranch()
                        ? $user->branch->currency
                        : ($branchId ? Branch::query()->whereKey($branchId)->value('currency') : setting('default_currency'));
                    $currency = $currency ?: setting('default_currency');

                    $inactiveStatuses = [
                        OrderStatus::Cancelled->value,
                        OrderStatus::Refunded->value,
                        OrderStatus::Merged->value,
                        OrderStatus::Completed->value,
                    ];
                    $completedStatuses = [OrderStatus::Completed->value];
                    $delayThreshold = now()->subMinutes(
                        max(5, (int) setting('pos_order_delay_minutes', 20))
                    );

                    $baseQuery = Order::query()
                        ->withOutGlobalBranchPermission()
                        ->when($branchId, fn(Builder $query) => $query->where('orders.branch_id', $branchId))
                        ->when(
                            $user->hasRole(DefaultRole::Waiter->value),
                            fn(Builder $query) => $query->where('orders.waiter_id', $user->id)
                        )
                        ->whereDate('orders.order_date', today());

                    $orders = (clone $baseQuery)
                        ->whereIn('orders.status', $completedStatuses)
                        ->leftJoin('users as customers', 'customers.id', '=', 'orders.customer_id')
                        ->leftJoin('users as waiters', 'waiters.id', '=', 'orders.waiter_id')
                        ->select([
                            'orders.id',
                            'orders.reference_no',
                            'orders.order_number',
                            'orders.table_id',
                            'orders.type',
                            'orders.status',
                            'orders.payment_status',
                            'orders.guest_count',
                            'orders.total',
                            'orders.created_at',
                            'customers.name as customer_name',
                            'waiters.name as waiter_name',
                        ])
                        ->with('table:id,name')
                        ->latest('orders.created_at')
                        ->limit(10)
                        ->get();

                    $stats = (clone $baseQuery)
                        ->selectRaw('COUNT(*) as total_orders')
                        ->selectRaw(
                            'SUM(CASE WHEN payment_status <> ? AND status NOT IN (?, ?, ?, ?) THEN 1 ELSE 0 END) as active_orders',
                            [OrderPaymentStatus::Paid->value, ...$inactiveStatuses]
                        )
                        ->selectRaw(
                            'SUM(CASE WHEN status IN (?) THEN 1 ELSE 0 END) as completed_orders',
                            $completedStatuses
                        )
                        ->selectRaw('SUM(CASE WHEN payment_status = ? THEN 1 ELSE 0 END) as unpaid_orders', [OrderPaymentStatus::Unpaid->value])
                        ->selectRaw(
                            'SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as cancelled_orders',
                            [OrderStatus::Cancelled->value, OrderStatus::Refunded->value]
                        )
                        ->selectRaw(
                            'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as ready_orders',
                            [OrderStatus::Ready->value]
                        )
                        ->selectRaw(
                            'SUM(CASE WHEN status = ? AND created_at <= ? THEN 1 ELSE 0 END) as delayed_orders',
                            [OrderStatus::Preparing->value, $delayThreshold]
                        )
                        ->selectRaw('COALESCE(SUM(guest_count), 0) as guest_count')
                        ->selectRaw('COUNT(DISTINCT table_id) as assigned_tables')
                        // Fulfilment timing. Only rows that actually reached the
                        // milestone are averaged, so orders still in progress do
                        // not drag the average toward zero.
                        //
                        // kot_finalized_at is not populated by the current order
                        // flow, so a kitchen-vs-service split is not derivable;
                        // these are order-placed -> served and order-placed ->
                        // settled instead.
                        ->selectRaw('AVG(CASE WHEN served_at IS NOT NULL THEN '.timestampDiffSql('SECOND', 'created_at', 'served_at').' END) as avg_serve_seconds')
                        ->selectRaw('AVG(CASE WHEN COALESCE(closed_at, payment_at) IS NOT NULL THEN '.timestampDiffSql('SECOND', 'created_at', 'COALESCE(closed_at, payment_at)').' END) as avg_settle_seconds')
                        ->selectRaw('SUM(CASE WHEN served_at IS NOT NULL THEN 1 ELSE 0 END) as served_orders')
                        ->selectRaw(
                            'COALESCE(SUM(CASE WHEN status IN (?) AND payment_status = ? THEN total ELSE 0 END), 0) as total_revenue',
                            [...$completedStatuses, OrderPaymentStatus::Paid->value]
                        )
                        ->first();

                    $totalOrders = (int) ($stats?->total_orders ?? 0);
                    $revenue = (float) ($stats?->total_revenue ?? 0);

                    return [
                        'branch_id' => $branchId,
                        'currency' => $currency,
                        'stats' => [
                            'total_orders' => $totalOrders,
                            'active_orders' => (int) ($stats?->active_orders ?? 0),
                            'completed_orders' => (int) ($stats?->completed_orders ?? 0),
                            'unpaid_orders' => (int) ($stats?->unpaid_orders ?? 0),
                            'cancelled_orders' => (int) ($stats?->cancelled_orders ?? 0),
                            'ready_orders' => (int) ($stats?->ready_orders ?? 0),
                            'delayed_orders' => (int) ($stats?->delayed_orders ?? 0),
                            'guest_count' => (int) ($stats?->guest_count ?? 0),
                            'assigned_tables' => (int) ($stats?->assigned_tables ?? 0),
                            'served_orders' => (int) ($stats?->served_orders ?? 0),
                            // Null when nothing reached the milestone today, so
                            // the client can show a dash rather than a fake 0.
                            'avg_serve_seconds' => $stats?->avg_serve_seconds === null
                                ? null
                                : (int) round((float) $stats->avg_serve_seconds),
                            'avg_settle_seconds' => $stats?->avg_settle_seconds === null
                                ? null
                                : (int) round((float) $stats->avg_settle_seconds),
                            'total_revenue' => (new Money($revenue, $currency))->toArray(),
                            'average_order_value' => (new Money($totalOrders > 0 ? $revenue / $totalOrders : 0, $currency))->toArray(),
                        ],
                        'orders' => $orders->map(function (object $order) use ($currency) {
                            $type = OrderType::coerce($order->type);
                            $status = OrderStatus::coerce($order->status);
                            $paymentStatus = OrderPaymentStatus::coerce($order->payment_status);

                            $rawTotal = method_exists($order, 'getRawOriginal')
                                ? $order->getRawOriginal('total')
                                : $order->total;

                            return [
                            'id' => $order->id,
                            'reference_no' => $order->reference_no,
                            'order_number' => $order->order_number,
                            'customer_name' => $order->customer_name ?? User::walkInName(),
                            'waiter_name' => $order->waiter_name,
                            'table' => $order->table_id ? [
                                'id' => $order->table_id,
                                'name' => $order->relationLoaded('table') ? $order->table?->name : null,
                            ] : null,
                            'type' => $type->toTrans(),
                            'status' => $status->toTrans(),
                            'payment_status' => [
                                'id' => $paymentStatus->value,
                                'name' => $paymentStatus->trans(),
                                'color' => match ($paymentStatus) {
                                    OrderPaymentStatus::Paid => 'success',
                                    OrderPaymentStatus::PartiallyPaid => 'warning',
                                    OrderPaymentStatus::Unpaid => 'error',
                                },
                            ],
                            'guest_count' => $order->guest_count,
                            'total' => (new Money((float) $rawTotal, $currency))->toArray(),
                            'created_at' => dateTimeFormat($order->created_at),
                            'time' => dateTimeFormat($order->created_at, \Modules\Support\Enums\DateTimeFormat::Time),
                            ];
                        })->values(),
                    ];
                }
            );
    }

}
