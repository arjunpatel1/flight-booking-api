<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class WaiterCancellationReport extends Report
{
    public function model(): string
    {
        return Order::class;
    }

    public function key(): string
    {
        return "waiter_cancellation";
    }

    public function attributes(): Collection
    {
        return collect([
            "period",
            "waiter",
            "total_orders",
            "cancelled_orders",
            "cancellation_percentage",
            "cancellation_amount",
            "average_cancellation_amount",
            "total_amount",
        ]);
    }

    public function columns(): array
    {
        $rate = $this->withRate ? 'orders.currency_rate' : '1';

        return [
            "orders.waiter_id",
            "COUNT(*) as total_orders",
            "SUM(CASE WHEN orders.status = '" . OrderStatus::Cancelled->value . "' THEN 1 ELSE 0 END) as cancelled_orders",
            "CASE WHEN COUNT(*) > 0 THEN (SUM(CASE WHEN orders.status = '" . OrderStatus::Cancelled->value . "' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)) ELSE 0 END as cancellation_percentage",
            "SUM(CASE WHEN orders.status = '" . OrderStatus::Cancelled->value . "' THEN orders.total * $rate ELSE 0 END) as cancellation_amount",
            "CASE WHEN SUM(CASE WHEN orders.status = '" . OrderStatus::Cancelled->value . "' THEN 1 ELSE 0 END) > 0 THEN SUM(CASE WHEN orders.status = '" . OrderStatus::Cancelled->value . "' THEN orders.total * $rate ELSE 0 END) / SUM(CASE WHEN orders.status = '" . OrderStatus::Cancelled->value . "' THEN 1 ELSE 0 END) ELSE 0 END as average_cancellation_amount",
            "SUM(orders.total * $rate) as total_amount",
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
            "cancelled_orders" => (int) $model->cancelled_orders,
            "cancellation_percentage" => round((float) $model->cancellation_percentage, 2),
            "cancellation_amount" => new Money((float) $model->cancellation_amount, $this->currency),
            "average_cancellation_amount" => new Money((float) $model->average_cancellation_amount, $this->currency),
            "total_amount" => new Money($model->total_amount->amount(), $this->currency),
        ];
    }

    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
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
