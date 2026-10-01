<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class BranchPerformanceReport extends Report
{
    /** @inheritDoc */
    public function key(): string
    {
        return "branch_performance";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "branch_name",
            "total_orders",
            "total"
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $rate = $this->withRate ? 'currency_rate' : '1';

        return [
            "branch_id",
            "MIN(created_at) as start_date",
            "MAX(created_at) as end_date",
            "COUNT(*) as total_orders",
            "SUM(total * $rate) as total"
        ];
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Order::class;
    }

    /** @inheritDoc */
    public function with(): array
    {
        return ["branch:id,name"];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "branch_name" => $model->branch->name,
            "total_orders" => (int)$model->total_orders,
            "total" => new Money($model->total->amount(), $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)->groupBy('branch_id')
        ];
    }

    /** @inheritDoc */
    public function filters(Request $request): array
    {
        return [
            [
                "key" => 'status',
                "label" => __('report::reports.filters.order_status'),
                "type" => 'select',
                "options" => OrderStatus::toArrayTrans(),
            ],
            [
                "key" => 'type',
                "label" => __('report::reports.filters.order_type'),
                "type" => 'select',
                "options" => OrderType::toArrayTrans(),
            ],
            [
                "key" => 'payment_status',
                "label" => __('report::reports.filters.payment_status'),
                "type" => 'select',
                "options" => OrderPaymentStatus::toArrayTrans(),
            ],
        ];
    }
}
