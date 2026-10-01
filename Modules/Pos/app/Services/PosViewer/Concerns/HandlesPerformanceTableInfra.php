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

trait HandlesPerformanceTableInfra
{
    private function tablePerformanceIntelligence(?int $branchId, User $user, array $periodWindow): array
    {
        if (! Schema::hasTable('tables')) {
            return [
                'summary' => $this->emptyPerformanceSummary('tables table missing'),
                'rows' => [],
                'source' => 'tables',
            ];
        }

        $negativeStatuses = [OrderStatus::Cancelled->value, OrderStatus::Refunded->value];
        $base = $this->performanceOrderQuery($branchId, $user, $periodWindow, $user->hasRole(DefaultRole::Waiter->value))
            ->whereNotNull('orders.table_id')
            ->whereNotIn('orders.status', $negativeStatuses);

        $branchAverage = (clone $base)
            ->selectRaw('AVG(CASE WHEN orders.closed_at IS NOT NULL THEN '.timestampDiffSql('MINUTE', 'orders.created_at', 'orders.closed_at').' ELSE NULL END) as average_turnover_minutes')
            ->selectRaw('AVG(orders.total) as average_bill')
            ->first();

        $rows = (clone $base)
            ->leftJoin('tables as t', 'orders.table_id', '=', 't.id')
            ->select('orders.table_id')
            ->selectRaw('MAX(t.name) as table_name')
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw('COALESCE(SUM(orders.total), 0) as revenue')
            ->selectRaw('AVG(orders.guest_count) as average_guest_count')
            ->selectRaw('AVG(orders.total) as average_bill')
            ->selectRaw('AVG(CASE WHEN orders.closed_at IS NOT NULL THEN '.timestampDiffSql('MINUTE', 'orders.created_at', 'orders.closed_at').' ELSE NULL END) as average_turnover_minutes')
            ->groupBy('orders.table_id')
            ->orderByDesc('revenue')
            ->limit(12)
            ->get()
            ->map(function ($row) use ($branchAverage) {
                $turnover = (float) ($row->average_turnover_minutes ?? 0);
                $avgTurnover = (float) ($branchAverage?->average_turnover_minutes ?? 0);
                $revenue = (float) ($row->revenue ?? 0);
                $hours = max(1, (($turnover * (int) $row->total_orders) / 60));

                return [
                    'table_id' => $row->table_id ? (int) $row->table_id : null,
                    'table_name' => (string) ($row->table_name ?: 'Table'),
                    'orders' => (int) $row->total_orders,
                    'revenue' => $this->performanceMoney($revenue),
                    'revenue_per_hour' => $this->performanceMoney($revenue / $hours),
                    'average_guest_count' => $this->roundNullable($row->average_guest_count),
                    'average_bill' => $this->performanceMoney((float) ($row->average_bill ?? 0)),
                    'average_turnover_minutes' => $this->roundNullable($row->average_turnover_minutes),
                    'score' => $this->performanceBoundedScore(100 - max(0, $turnover - max(45, $avgTurnover)) * 1.4),
                ];
            })
            ->values()
            ->all();

        $idleCount = $this->tableIdleAfterPaymentOrders($branchId, $user)->count();
        $occupiedWithoutOrder = Schema::hasTable('orders')
            ? Table::query()
                ->withOutGlobalBranchPermission()
                ->when($branchId, fn(Builder $query) => $query->where('branch_id', $branchId))
                ->where('status', TableStatus::Occupied->value)
                ->whereDoesntHave('activeOrders')
                ->count()
            : 0;

        return [
            'summary' => [
                'tracked_tables' => count($rows),
                'average_turnover_minutes' => $this->roundNullable($branchAverage?->average_turnover_minutes),
                'average_bill' => $this->performanceMoney((float) ($branchAverage?->average_bill ?? 0)),
                'idle_after_payment_count' => $idleCount,
                'occupied_without_order_count' => $occupiedWithoutOrder,
            ],
            'rows' => $rows,
            'source' => 'orders.table_id',
        ];
    }

    private function printPerformanceIntelligence(?int $branchId, array $periodWindow): array
    {
        if (! Schema::hasTable('print_jobs')) {
            return [
                'summary' => $this->emptyPerformanceSummary('print_jobs table missing'),
                'source' => 'print_jobs',
            ];
        }

        $summary = PrintJob::query()
            ->withOutGlobalBranchPermission()
            ->when($branchId, fn(Builder $query) => $query->where('branch_id', $branchId))
            ->whereBetween('created_at', [$periodWindow['from'], $periodWindow['to']])
            ->selectRaw('COUNT(*) as total_jobs')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed_jobs', [PrintJobStatus::Failed->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_jobs', [PrintJobStatus::Pending->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed_jobs', [PrintJobStatus::Success->value])
            ->selectRaw('AVG(CASE WHEN completed_at IS NOT NULL THEN '.timestampDiffSql('SECOND', 'created_at', 'completed_at').' ELSE NULL END) as average_completion_seconds')
            ->first();

        return [
            'summary' => [
                'total_jobs' => (int) ($summary?->total_jobs ?? 0),
                'failed_jobs' => (int) ($summary?->failed_jobs ?? 0),
                'pending_jobs' => (int) ($summary?->pending_jobs ?? 0),
                'completed_jobs' => (int) ($summary?->completed_jobs ?? 0),
                'average_completion_seconds' => $this->roundNullable($summary?->average_completion_seconds),
            ],
            'source' => 'print_jobs',
        ];
    }

    private function terminalPerformanceIntelligence(?int $branchId, User $user): array
    {
        if (! Schema::hasTable('pos_terminal_devices')) {
            return [
                'summary' => $this->emptyPerformanceSummary('pos_terminal_devices table missing'),
                'source' => 'pos_terminal_devices',
            ];
        }

        $offlineAfterSeconds = (int) config('pos.fleet.offline_after_seconds', 90);
        $devices = PosTerminalDevice::query()
            ->withOutGlobalBranchPermission()
            ->when($branchId, fn(Builder $query) => $query->where('branch_id', $branchId))
            ->when(
                $user->hasRole(DefaultRole::Waiter->value),
                fn(Builder $query) => $query->where('created_by', $user->id)
            )
            ->latest('last_seen_at')
            ->limit(50)
            ->get();

        $online = 0;
        $queue = 0;
        $errors = 0;

        foreach ($devices as $device) {
            $status = $device->toStatusPayload($offlineAfterSeconds)['status'] ?? $device->status;
            $online += $status === 'online' ? 1 : 0;
            $errors += in_array($status, ['offline', 'error'], true) ? 1 : 0;
            $queue += (int) $device->local_queue_count + (int) $device->server_queue_count;
        }

        return [
            'summary' => [
                'total_devices' => $devices->count(),
                'online_devices' => $online,
                'error_devices' => $errors,
                'queue_count' => $queue,
            ],
            'source' => 'pos_terminal_devices',
        ];
    }

    private function revenuePerformanceIntelligence(?int $branchId, User $user, array $periodWindow): array
    {
        $negativeStatuses = [OrderStatus::Cancelled->value, OrderStatus::Refunded->value];
        $current = (clone $this->performanceOrderQuery($branchId, $user, $periodWindow, $user->hasRole(DefaultRole::Waiter->value)))
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(CASE WHEN orders.status NOT IN (?, ?) THEN orders.total ELSE 0 END), 0) as revenue', $negativeStatuses)
            ->first();

        $comparison = (clone $this->performanceOrderQuery(
            $branchId,
            $user,
            ['from' => $periodWindow['comparison_from'], 'to' => $periodWindow['comparison_to']],
            $user->hasRole(DefaultRole::Waiter->value)
        ))
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(CASE WHEN orders.status NOT IN (?, ?) THEN orders.total ELSE 0 END), 0) as revenue', $negativeStatuses)
            ->first();

        $revenue = (float) ($current?->revenue ?? 0);
        $previousRevenue = (float) ($comparison?->revenue ?? 0);

        return [
            'summary' => [
                'orders' => (int) ($current?->orders ?? 0),
                'revenue' => $this->performanceMoney($revenue),
                'comparison_orders' => (int) ($comparison?->orders ?? 0),
                'comparison_revenue' => $this->performanceMoney($previousRevenue),
                'revenue_change_percent' => $this->percentageChange($previousRevenue, $revenue),
            ],
            'source' => 'orders.total',
        ];
    }

}
