<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Report\Models\FactShiftDaily;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class ShiftPerformanceReport extends Report
{
    public function model(): string
    {
        return FactShiftDaily::class;
    }

    public function key(): string
    {
        return "shift_performance";
    }

    public function attributes(): Collection
    {
        return collect([
            "period",
            "shift",
            "user",
            "total_orders",
            "completed_orders",
            "cancelled_orders",
            "gross_sales",
            "net_sales",
            "average_order_value",
            "orders_per_hour",
            "sales_per_hour",
            "cash_over_short",
        ]);
    }

    public function columns(): array
    {
        return [
            "shift_id",
            "user_id",
            "MIN(business_date) as start_date",
            "MAX(business_date) as end_date",
            "MAX(currency) as currency",
            "SUM(total_orders) as total_orders",
            "SUM(completed_orders) as completed_orders",
            "SUM(cancelled_orders) as cancelled_orders",
            "SUM(gross_sales) as gross_sales",
            "SUM(net_sales) as net_sales",
            "AVG(average_order_value) as average_order_value",
            "AVG(orders_per_hour) as orders_per_hour",
            "AVG(sales_per_hour) as sales_per_hour",
            "SUM(cash_over_short) as cash_over_short",
        ];
    }

    public function resource(Model $model): array
    {
        $currency = $model->currency ?: $this->currency;

        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "shift" => $model->shift_id,
            "user" => $model->user?->name ?: __("report::reports.unassigned"),
            "total_orders" => (int) $model->total_orders,
            "completed_orders" => (int) $model->completed_orders,
            "cancelled_orders" => (int) $model->cancelled_orders,
            "gross_sales" => new Money((float) $model->gross_sales, $currency),
            "net_sales" => new Money((float) $model->net_sales, $currency),
            "average_order_value" => new Money((float) $model->average_order_value, $currency),
            "orders_per_hour" => round((float) $model->orders_per_hour, 2),
            "sales_per_hour" => new Money((float) $model->sales_per_hour, $currency),
            "cash_over_short" => new Money((float) $model->cash_over_short, $currency),
        ];
    }

    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->groupBy(["shift_id", "user_id"]),
        ];
    }

    public function filters(Request $request): array
    {
        return [
            [
                "key" => 'shift_id',
                "label" => __('report::reports.filters.shift'),
                "type" => 'select',
                "options" => \Modules\User\Models\EmployeeShift::query()
                    ->where('is_active', true)
                    ->select('id', 'name')
                    ->orderBy('name')
                    ->get()
                    ->map(fn($shift) => [
                        'id' => $shift->id,
                        'name' => $shift->name,
                    ])
                    ->all(),
            ],
        ];
    }

    public function with(): array
    {
        return [
            "user:id,name",
        ];
    }

    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "business_date";
    }
}
