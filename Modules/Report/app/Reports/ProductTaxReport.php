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

class ProductTaxReport extends Report
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "order_taxes.created_at";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return OrderTax::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "product_tax";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "tax_name",
            "product_name",
            "total_products",
            "total"
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $rate = $this->withRate ? 'order_taxes.currency_rate' : '1';

        return [
            "order_taxes.tax_id",
            "op.product_id",
            "MAX(order_taxes.order_product_id) as order_product_id",
            "MIN(order_taxes.created_at) as start_date",
            "MAX(order_taxes.created_at) as end_date",
            "SUM(op.quantity) as total_products",
            "SUM(order_taxes.amount * $rate) as total"
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "tax_name" => $model->tax->name,
            "product_name" => $model->orderProduct->name,
            "total_products" => (int)$model->total_products,
            "total" => new Money($model->total, $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->when($this->user->assignedToBranch(), fn(Builder $query) => $query->branchId($this->user->branch_id))
                ->whereNotNull("order_product_id")
                ->join('order_products as op', 'order_taxes.order_product_id', '=', 'op.id')
                ->groupBy('order_taxes.tax_id', 'op.product_id')
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
        return [
            "tax" => fn($query) => $query->select('id', 'name')->without("branch"),
            "orderProduct" => fn($query) => $query->with([
                "product" => fn($query) => $query->select('id', 'name')->without("branch")
            ]),
        ];
    }

    /** @inheritDoc */
    public function hasSearch(): bool
    {
        return true;
    }
}
