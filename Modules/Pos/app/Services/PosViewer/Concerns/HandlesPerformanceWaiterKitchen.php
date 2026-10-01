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

trait HandlesPerformanceWaiterKitchen
{
    private function waiterPerformanceIntelligence(?int $branchId, User $user, array $periodWindow): array
    {
        $scopeWaiter = $user->hasRole(DefaultRole::Waiter->value);
        $base = $this->performanceOrderQuery($branchId, $user, $periodWindow, $scopeWaiter);
        $branchBase = $this->performanceOrderQuery($branchId, $user, $periodWindow, false);
        $comparisonBase = $this->performanceOrderQuery(
            $branchId,
            $user,
            ['from' => $periodWindow['comparison_from'], 'to' => $periodWindow['comparison_to']],
            $scopeWaiter
        );

        $activeStatuses = [
            OrderStatus::Pending->value,
            OrderStatus::Confirmed->value,
            OrderStatus::Preparing->value,
            OrderStatus::Ready->value,
            OrderStatus::Served->value,
        ];
        $completedStatuses = [OrderStatus::Completed->value, OrderStatus::Served->value];
        $negativeStatuses = [OrderStatus::Cancelled->value, OrderStatus::Refunded->value];
        $kotSelect = Schema::hasColumn('orders', 'kot_finalized_at')
            ? 'AVG(CASE WHEN orders.kot_finalized_at IS NOT NULL THEN '.timestampDiffSql('MINUTE', 'orders.created_at', 'orders.kot_finalized_at').' ELSE NULL END) as avg_kot_minutes'
            : 'NULL as avg_kot_minutes';

        $summary = (clone $base)
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw('SUM(CASE WHEN orders.status IN (?, ?, ?, ?, ?) THEN 1 ELSE 0 END) as active_orders', $activeStatuses)
            ->selectRaw('SUM(CASE WHEN orders.status IN (?, ?) THEN 1 ELSE 0 END) as completed_orders', $completedStatuses)
            ->selectRaw('SUM(CASE WHEN orders.status IN (?, ?) THEN 1 ELSE 0 END) as correction_orders', $negativeStatuses)
            ->selectRaw('SUM(CASE WHEN orders.payment_status IN (?, ?) THEN 1 ELSE 0 END) as payment_pending', [
                OrderPaymentStatus::Unpaid->value,
                OrderPaymentStatus::PartiallyPaid->value,
            ])
            ->selectRaw('COALESCE(SUM(CASE WHEN orders.status NOT IN (?, ?) THEN orders.guest_count ELSE 0 END), 0) as guests_served', $negativeStatuses)
            ->selectRaw('COALESCE(SUM(CASE WHEN orders.status NOT IN (?, ?) THEN orders.total ELSE 0 END), 0) as revenue', $negativeStatuses)
            ->selectRaw('AVG(CASE WHEN orders.status NOT IN (?, ?) THEN orders.total ELSE NULL END) as average_order_value', $negativeStatuses)
            ->selectRaw('AVG(CASE WHEN orders.closed_at IS NOT NULL THEN '.timestampDiffSql('MINUTE', 'orders.created_at', 'orders.closed_at').' ELSE NULL END) as average_service_minutes')
            ->selectRaw('AVG(CASE WHEN orders.payment_at IS NOT NULL THEN '.timestampDiffSql('MINUTE', 'orders.created_at', 'orders.payment_at').' ELSE NULL END) as average_payment_minutes')
            ->selectRaw($kotSelect)
            ->first();

        $branchAverage = (clone $branchBase)
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw('AVG(CASE WHEN orders.closed_at IS NOT NULL THEN '.timestampDiffSql('MINUTE', 'orders.created_at', 'orders.closed_at').' ELSE NULL END) as average_service_minutes')
            ->selectRaw('AVG(CASE WHEN orders.payment_at IS NOT NULL THEN '.timestampDiffSql('MINUTE', 'orders.created_at', 'orders.payment_at').' ELSE NULL END) as average_payment_minutes')
            ->selectRaw('AVG(CASE WHEN orders.status NOT IN (?, ?) THEN orders.total ELSE NULL END) as average_order_value', $negativeStatuses)
            ->first();

        $comparison = (clone $comparisonBase)
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw('COALESCE(SUM(CASE WHEN orders.status NOT IN (?, ?) THEN orders.total ELSE 0 END), 0) as revenue', $negativeStatuses)
            ->first();

        $waiterRows = (clone $branchBase)
            ->leftJoin('users as waiters', 'orders.waiter_id', '=', 'waiters.id')
            ->select('orders.waiter_id')
            ->selectRaw("COALESCE(waiters.name, 'Unassigned') as waiter_name")
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw('COALESCE(SUM(CASE WHEN orders.status NOT IN (?, ?) THEN orders.total ELSE 0 END), 0) as revenue', $negativeStatuses)
            ->selectRaw('AVG(CASE WHEN orders.closed_at IS NOT NULL THEN '.timestampDiffSql('MINUTE', 'orders.created_at', 'orders.closed_at').' ELSE NULL END) as average_service_minutes')
            ->groupBy('orders.waiter_id', 'waiters.name')
            ->orderByDesc('revenue')
            ->limit(8)
            ->get()
            ->map(fn($row) => [
                'waiter_id' => $row->waiter_id ? (int) $row->waiter_id : null,
                'waiter_name' => (string) $row->waiter_name,
                'orders' => (int) $row->total_orders,
                'revenue' => $this->performanceMoney((float) $row->revenue),
                'average_service_minutes' => $this->roundNullable($row->average_service_minutes),
                'score' => $this->performanceBoundedScore(100 - max(0, ((float) ($row->average_service_minutes ?? 0) - 35) * 2)),
            ])
            ->values()
            ->all();

        $totalOrders = (int) ($summary?->total_orders ?? 0);
        $periodHours = max(1, $periodWindow['from']->diffInMinutes($periodWindow['to']) / 60);
        $revenue = (float) ($summary?->revenue ?? 0);

        return [
            'scope' => $scopeWaiter ? 'logged_in_waiter' : 'branch',
            'summary' => [
                'orders_handled' => $totalOrders,
                'active_orders' => (int) ($summary?->active_orders ?? 0),
                'completed_orders' => (int) ($summary?->completed_orders ?? 0),
                'guests_served' => (int) ($summary?->guests_served ?? 0),
                'orders_per_hour' => round($totalOrders / $periodHours, 2),
                'revenue_handled' => $this->performanceMoney($revenue),
                'average_order_value' => $this->performanceMoney((float) ($summary?->average_order_value ?? 0)),
                'average_order_taking_minutes' => $this->roundNullable($summary?->avg_kot_minutes),
                'average_kot_minutes' => $this->roundNullable($summary?->avg_kot_minutes),
                'average_payment_minutes' => $this->roundNullable($summary?->average_payment_minutes),
                'average_table_turnover_minutes' => $this->roundNullable($summary?->average_service_minutes),
                'order_correction_rate' => $totalOrders > 0 ? round(((int) ($summary?->correction_orders ?? 0) / $totalOrders) * 100, 2) : 0,
                'payment_pending' => (int) ($summary?->payment_pending ?? 0),
            ],
            'restaurant_average' => [
                'orders' => (int) ($branchAverage?->total_orders ?? 0),
                'average_service_minutes' => $this->roundNullable($branchAverage?->average_service_minutes),
                'average_payment_minutes' => $this->roundNullable($branchAverage?->average_payment_minutes),
                'average_order_value' => $this->performanceMoney((float) ($branchAverage?->average_order_value ?? 0)),
            ],
            'comparison' => [
                'orders_change_percent' => $this->percentageChange((float) ($comparison?->total_orders ?? 0), (float) $totalOrders),
                'revenue_change_percent' => $this->percentageChange((float) ($comparison?->revenue ?? 0), $revenue),
            ],
            'rows' => $waiterRows,
            'source' => 'orders',
        ];
    }

    private function kitchenPerformanceIntelligence(?int $branchId, User $user, array $periodWindow): array
    {
        if (! Schema::hasTable('order_products')) {
            return [
                'summary' => $this->emptyPerformanceSummary('order_products table missing'),
                'rows' => [],
                'source' => 'order_products',
            ];
        }

        $sla = app(KitchenSla::class);
        $slaMinutes = $sla->thresholdMinutes();
        $hasKotSentAt = Schema::hasColumn('order_products', 'kot_sent_at');
        $hasPreparationSeconds = Schema::hasColumn('order_products', 'preparation_time_seconds');
        $delayColumn = $hasKotSentAt ? 'op.kot_sent_at' : 'op.created_at';
        $base = DB::table('order_products as op')
            ->join('orders as o', 'op.order_id', '=', 'o.id')
            ->when($branchId, fn($query) => $query->where('o.branch_id', $branchId))
            ->when(
                $user->hasRole(DefaultRole::Waiter->value),
                fn($query) => $query->where('o.waiter_id', $user->id)
            )
            ->whereBetween('o.created_at', [$periodWindow['from'], $periodWindow['to']]);

        $prepAvgSelect = $hasPreparationSeconds
            ? 'AVG(op.preparation_time_seconds) / 60 as average_preparation_minutes'
            : 'NULL as average_preparation_minutes';
        $prepMaxSelect = $hasPreparationSeconds
            ? 'MAX(op.preparation_time_seconds) / 60 as longest_preparation_minutes'
            : 'NULL as longest_preparation_minutes';

        $summary = (clone $base)
            ->selectRaw('COUNT(*) as total_items')
            ->selectRaw('COUNT(DISTINCT op.order_id) as kot_volume')
            ->selectRaw('SUM(CASE WHEN op.status IN (?, ?) THEN 1 ELSE 0 END) as active_items', [
                OrderProductStatus::Pending->value,
                OrderProductStatus::Preparing->value,
            ])
            ->selectRaw('SUM(CASE WHEN op.status IN (?, ?) THEN 1 ELSE 0 END) as completed_items', [
                OrderProductStatus::Ready->value,
                OrderProductStatus::Served->value,
            ])
            ->selectRaw("SUM(CASE WHEN op.status IN (?, ?) AND {$delayColumn} <= ? THEN 1 ELSE 0 END) as delayed_items", [
                OrderProductStatus::Pending->value,
                OrderProductStatus::Preparing->value,
                now()->subMinutes($slaMinutes),
            ])
            ->selectRaw($prepAvgSelect)
            ->selectRaw($prepMaxSelect)
            ->first();

        $products = (clone $base)
            ->leftJoin('products as p', 'op.product_id', '=', 'p.id')
            ->select('op.product_id')
            ->selectRaw('MAX(CAST(p.name AS CHAR)) as product_name')
            ->selectRaw('SUM(op.quantity) as quantity')
            ->selectRaw("SUM(CASE WHEN op.status IN (?, ?) AND {$delayColumn} <= ? THEN 1 ELSE 0 END) as delayed_items", [
                OrderProductStatus::Pending->value,
                OrderProductStatus::Preparing->value,
                now()->subMinutes($slaMinutes),
            ])
            ->when($hasPreparationSeconds, fn($query) => $query->selectRaw('AVG(op.preparation_time_seconds) / 60 as average_preparation_minutes'))
            ->groupBy('op.product_id')
            ->orderByDesc('delayed_items')
            ->orderByDesc('quantity')
            ->limit(8)
            ->get()
            ->map(fn($row) => [
                'product_id' => $row->product_id ? (int) $row->product_id : null,
                'product_name' => $this->localizedString($row->product_name ?? 'Product'),
                'quantity' => (int) $row->quantity,
                'delayed_items' => (int) $row->delayed_items,
                'average_preparation_minutes' => $this->roundNullable($row->average_preparation_minutes ?? null),
            ])
            ->values()
            ->all();

        $total = (int) ($summary?->total_items ?? 0);
        $delayed = (int) ($summary?->delayed_items ?? 0);

        return [
            'summary' => [
                'kot_volume' => (int) ($summary?->kot_volume ?? 0),
                'total_items' => $total,
                'active_items' => (int) ($summary?->active_items ?? 0),
                'completed_items' => (int) ($summary?->completed_items ?? 0),
                'delayed_items' => $delayed,
                'delay_rate_percent' => $total > 0 ? round(($delayed / $total) * 100, 2) : 0,
                'average_preparation_minutes' => $this->roundNullable($summary?->average_preparation_minutes),
                'longest_preparation_minutes' => $this->roundNullable($summary?->longest_preparation_minutes),
                'sla_minutes' => $slaMinutes,
            ],
            'rows' => $products,
            'source' => $hasKotSentAt ? 'order_products.kot_sent_at' : 'order_products.created_at',
        ];
    }

}
