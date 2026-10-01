<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Report\Models\FactKotDaily;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;

class KotDelayReport extends Report
{
    public function model(): string
    {
        return FactKotDaily::class;
    }

    public function key(): string
    {
        return "kot_delay";
    }

    public function attributes(): Collection
    {
        return collect([
            "period",
            "hour",
            "kitchen_station",
            "total_kots",
            "on_time_kots",
            "delayed_kots",
            "avg_kot_time_minutes",
            "avg_delay_minutes",
            "total_quantity",
        ]);
    }

    public function columns(): array
    {
        return [
            "hour",
            "kitchen_station_id",
            "MIN(business_date) as start_date",
            "MAX(business_date) as end_date",
            "MAX(currency) as currency",
            "SUM(total_kots) as total_kots",
            "SUM(on_time_kots) as on_time_kots",
            "SUM(delayed_kots) as delayed_kots",
            "AVG(avg_kot_time_minutes) as avg_kot_time_minutes",
            "AVG(avg_delay_minutes) as avg_delay_minutes",
            "SUM(total_quantity) as total_quantity",
        ];
    }

    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "hour" => $model->hour,
            "kitchen_station" => $model->kitchenStation?->name ?: __("report::reports.unassigned"),
            "total_kots" => (int) $model->total_kots,
            "on_time_kots" => (int) $model->on_time_kots,
            "delayed_kots" => (int) $model->delayed_kots,
            "avg_kot_time_minutes" => round((float) $model->avg_kot_time_minutes, 2),
            "avg_delay_minutes" => round((float) $model->avg_delay_minutes, 2),
            "total_quantity" => (int) $model->total_quantity,
        ];
    }

    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->whereNotNull('hour')
                ->groupBy(["hour", "kitchen_station_id"]),
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
            [
                "key" => 'hour',
                "label" => __('report::reports.filters.hour'),
                "type" => 'select',
                "options" => collect(range(0, 23))->map(fn($h) => [
                    'id' => $h,
                    'name' => sprintf('%02d:00', $h),
                ])->all(),
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
