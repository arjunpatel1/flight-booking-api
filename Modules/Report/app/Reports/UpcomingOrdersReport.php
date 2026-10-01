<?php

namespace Modules\Report\Reports;

use Closure;
use DB;
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
use Modules\Support\GlobalStructureFilters;
use Modules\Support\Money;

class UpcomingOrdersReport extends Report
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "scheduled_at";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Order::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "upcoming_orders";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "total_orders",
            "total_products",
            "subtotal",
            "tax",
            "total",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $rate = $this->withRate ? 'currency_rate' : '1';

        return [
            "MIN(scheduled_at) as start_date",
            "MAX(scheduled_at) as end_date",
            "COUNT(*) as total_orders",
            "SUM(op.quantity) as total_products",
            "SUM(subtotal * $rate) as subtotal",
            "SUM(ot.amount * $rate) as tax",
            "SUM(orders.total * $rate) as total"
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "total_orders" => (int)$model->total_orders,
            "total_products" => (int)$model->total_products,
            "subtotal" => new Money($model->subtotal->amount(), $this->currency),
            "tax" => new Money($model->tax, $this->currency),
            "total" => new Money($model->total->amount(), $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->whereDate("orders.scheduled_at", ">", today())
                ->activeOrders()
                ->join(
                    DB::raw('(SELECT order_id, sum(quantity) quantity FROM order_products GROUP BY order_id) op'),
                    fn($join) => $join->on('orders.id', '=', 'op.order_id')
                )
                ->leftJoin(
                    DB::raw('(SELECT order_id, sum(amount) amount FROM order_taxes GROUP BY order_id) ot'),
                    fn($join) => $join->on('orders.id', '=', 'ot.order_id')
                )
                ->when(!$this->hasGroupByData($request), fn($query) => $query->groupBy('orders.id'))
        ];
    }

    /**
     * Get global filters
     *
     * @return array
     */
    public function globalFilters(): array
    {
        $branchFilter = GlobalStructureFilters::branch();
        $minDate = today();
        $maxDate = today()->addYear();

        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            [
                "key" => 'from',
                "label" => __('admin::admin.filters.from'),
                "type" => 'date',
                "min" => $minDate,
                "max" => $maxDate,
            ],
            [
                "key" => 'to',
                "label" => __('admin::admin.filters.to'),
                "type" => 'date',
                "min" => $minDate,
                "max" => $maxDate,
            ],
            GlobalStructureFilters::groupByDate()
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
                "options" => OrderStatus::toArrayTrans([
                    OrderStatus::Refunded->value,
                    OrderStatus::Cancelled->value,
                    OrderStatus::Merged->value,
                    OrderStatus::Completed->value,
                    OrderStatus::Served->value,
                    OrderStatus::Ready->value,
                    OrderStatus::Preparing->value,
                ]),
            ],
            [
                "key" => 'type',
                "label" => __('report::reports.filters.order_type'),
                "type" => 'select',
                "options" => OrderType::toArrayTrans([
                    OrderType::Takeaway->value,
                    OrderType::DineIn->value,
                    OrderType::Pickup->value,
                    OrderType::DriveThru->value,
                ]),
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
