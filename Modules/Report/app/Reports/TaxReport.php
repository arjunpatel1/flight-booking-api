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
use Modules\Order\Models\OrderTax;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class TaxReport extends Report
{
    /** @inheritDoc */
    public function key(): string
    {
        return "tax";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "tax_name",
            "total_orders",
            "total"
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $rate = $this->withRate ? 'currency_rate' : '1';

        return [
            "tax_id",
            "MIN(created_at) as start_date",
            "MAX(created_at) as end_date",
            "COUNT(*) as total_orders",
            "SUM(amount * $rate) as total"
        ];
    }

    /** @inheritDoc */
    public function model(): string
    {
        return OrderTax::class;
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "tax_name" => $model->tax->name,
            "total_orders" => (int)$model->total_orders,
            "total" => new Money($model->total, $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->when($this->user->assignedToBranch(), fn(Builder $query) => $query->branchId($this->user->branch_id))
                ->whereNull("order_product_id")
                ->groupBy('tax_id')
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
    public function with(): array
    {
        return ["tax" => fn($query) => $query->select('id', 'name')->without("branch")];
    }

    /** @inheritDoc */
    public function hasSearch(): bool
    {
        return true;
    }
}
