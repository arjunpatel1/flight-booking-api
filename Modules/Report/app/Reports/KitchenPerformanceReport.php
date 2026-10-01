<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Report\Models\FactKitchenDaily;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class KitchenPerformanceReport extends Report
{
    public function model(): string
    {
        return FactKitchenDaily::class;
    }

    public function key(): string
    {
        return "kitchen_performance";
    }

    public function attributes(): Collection
    {
        return collect([
            "period",
            "kitchen_station",
            "total_orders",
            "completed_orders",
            "delayed_orders",
            "avg_preparation_time_minutes",
            "avg_kot_delay_minutes",
            "max_preparation_time_minutes",
            "orders_per_hour",
            "on_time_percentage",
        ]);
    }

    public function columns(): array
    {
        return [
            "kitchen_station_id",
            "MIN(business_date) as start_date",
            "MAX(business_date) as end_date",
            "MAX(currency) as currency",
            "SUM(total_orders) as total_orders",
            "SUM(completed_orders) as completed_orders",
            "SUM(delayed_orders) as delayed_orders",
            "AVG(avg_preparation_time_minutes) as avg_preparation_time_minutes",
            "AVG(avg_kot_delay_minutes) as avg_kot_delay_minutes",
            "MAX(max_preparation_time_minutes) as max_preparation_time_minutes",
            "AVG(orders_per_hour) as orders_per_hour",
            "AVG(on_time_percentage) as on_time_percentage",
        ];
    }

    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "kitchen_station" => $model->kitchenStation?->name ?: __("report::reports.unassigned"),
            "total_orders" => (int) $model->total_orders,
            "completed_orders" => (int) $model->completed_orders,
            "delayed_orders" => (int) $model->delayed_orders,
            "avg_preparation_time_minutes" => round((float) $model->avg_preparation_time_minutes, 2),
            "avg_kot_delay_minutes" => round((float) $model->avg_kot_delay_minutes, 2),
            "max_preparation_time_minutes" => (int) $model->max_preparation_time_minutes,
            "orders_per_hour" => round((float) $model->orders_per_hour, 2),
            "on_time_percentage" => round((float) $model->on_time_percentage, 2),
        ];
    }

    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->groupBy('kitchen_station_id'),
        ];
    }

    public function filters(Request $request): array
    {
        return [
            [
                "key" => 'kitchen_station_id',
                "label" => __('report::reports.filters.kitchen_station'),
                "type" => 'select',
                "options" => \Modules\Pos\Models\KitchenStation::query()
                    ->where('is_active', true)
                    ->select('id', 'name')
                    ->orderBy('name')
                    ->get()
                    ->map(fn($station) => [
                        'id' => $station->id,
                        'name' => $station->name,
                    ])
                    ->all(),
            ],
        ];
    }

    public function with(): array
    {
        return [
            "kitchenStation:id,name",
        ];
    }

    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "business_date";
    }
}
