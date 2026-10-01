<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class WaiterServiceTimeReport extends Report
{
    public function model(): string
    {
        return Order::class;
    }

    public function key(): string
    {
        return "waiter_service_time";
    }

    public function attributes(): Collection
    {
        return collect([
            "period",
            "waiter",
            "total_orders",
            "completed_orders",
            "average_service_minutes",
            "min_service_minutes",
            "max_service_minutes",
            "fast_orders",
            "slow_orders",
            "average_guest_count",
        ]);
    }

    public function columns(): array
    {
        return [
            "orders.waiter_id",
            "COUNT(*) as total_orders",
            "SUM(CASE WHEN orders.status = '" . OrderStatus::Completed->value . "' THEN 1 ELSE 0 END) as completed_orders",
            'AVG(CASE WHEN orders.closed_at IS NOT NULL THEN '.timestampDiffSql('MINUTE', 'orders.created_at', 'orders.closed_at').' ELSE NULL END) as average_service_minutes',
            'MIN(CASE WHEN orders.closed_at IS NOT NULL THEN '.timestampDiffSql('MINUTE', 'orders.created_at', 'orders.closed_at').' ELSE NULL END) as min_service_minutes',
            'MAX(CASE WHEN orders.closed_at IS NOT NULL THEN '.timestampDiffSql('MINUTE', 'orders.created_at', 'orders.closed_at').' ELSE NULL END) as max_service_minutes',
            'SUM(CASE WHEN orders.closed_at IS NOT NULL AND '.timestampDiffSql('MINUTE', 'orders.created_at', 'orders.closed_at').' <= 15 THEN 1 ELSE 0 END) as fast_orders',
            'SUM(CASE WHEN orders.closed_at IS NOT NULL AND '.timestampDiffSql('MINUTE', 'orders.created_at', 'orders.closed_at').' > 45 THEN 1 ELSE 0 END) as slow_orders',
            "AVG(orders.guest_count) as average_guest_count",
            "MIN(orders.created_at) as start_date",
            "MAX(orders.created_at) as end_date",
        ];
    }

    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "waiter" => $model->waiter?->name ?: __("report::reports.unassigned"),
            "total_orders" => (int) $model->total_orders,
            "completed_orders" => (int) $model->completed_orders,
            "average_service_minutes" => round((float) $model->average_service_minutes, 1),
            "min_service_minutes" => (int) $model->min_service_minutes,
            "max_service_minutes" => (int) $model->max_service_minutes,
            "fast_orders" => (int) $model->fast_orders,
            "slow_orders" => (int) $model->slow_orders,
            "average_guest_count" => round((float) $model->average_guest_count, 1),
        ];
    }

    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->withoutCanceledOrders()
                ->whereNotNull('closed_at')
                ->groupBy('orders.waiter_id'),
        ];
    }

    public function filters(Request $request): array
    {
        return [
            [
                "key" => 'waiter_id',
                "label" => __('report::reports.filters.waiter'),
                "type" => 'select',
                "options" => User::query()
                    ->withOutGlobalBranchPermission()
                    ->role(DefaultRole::Waiter->value)
                    ->select('id', 'name')
                    ->orderBy('name')
                    ->get()
                    ->map(fn(User $user) => [
                        'id' => $user->id,
                        'name' => $user->name,
                    ])
                    ->all(),
            ],
        ];
    }

    public function with(): array
    {
        return [
            "waiter:id,name",
        ];
    }

    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "orders.created_at";
    }
}
