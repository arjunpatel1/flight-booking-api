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
use Modules\Order\Models\OrderProduct;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class CostRevenueReportByProductReport extends Report
{
    /** @inheritDoc */
    public function key(): string
    {
        return "cost_and_revenue_by_product";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "product",
            "quantity",
            "total_cost",
            "revenue",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $rate = $this->withRate ? 'currency_rate' : '1';

        return [
            "product_id",
            "MIN(created_at) as start_date",
            "MAX(created_at) as end_date",
            "SUM(quantity) as quantity",
            "SUM(cost_price * $rate) as total_cost",
            "SUM(revenue * $rate) as revenue",
        ];
    }

    /** @inheritDoc */
    public function model(): string
    {
        return OrderProduct::class;
    }

    /** @inheritDoc */
    public function with(): array
    {
        return ["product" => fn($query) => $query->select('id', 'name')->without(['branch'])];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "product" => $model->product?->name ?? '-',
            "quantity" => $model->quantity,
            "total_cost" => new Money($model->total_cost, $this->currency),
            "revenue" => new Money($model->revenue->amount(), $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->where('cost_price', ">", 0)
                ->when($this->user->assignedToBranch(), fn(Builder $query) => $query->branchId($this->user->branch_id))
                ->without(['product', 'taxes', 'options'])
                ->groupBy('product_id')
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

    /** @inheritDoc */
    public function hasSearch(): bool
    {
        return true;
    }
}
