<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class SalesByWaiterReport extends Report
{
    public function model(): string
    {
        return Order::class;
    }

    public function key(): string
    {
        return "sales_by_waiter";
    }

    public function attributes(): Collection
    {
        return collect([
            "period",
            "waiter",
            "total_orders",
            "completed_orders",
            "active_orders",
            "total_guests",
            "assigned_tables",
            "total_products",
            "subtotal",
            "tax",
            "total",
            "average_order_value",
            "average_service_minutes",
        ]);
    }

    public function columns(): array
    {
        $rate = $this->withRate ? 'orders.currency_rate' : '1';

        return [
            "orders.waiter_id",
            "COUNT(*) as total_orders",
            "SUM(CASE WHEN orders.status = '" . OrderStatus::Completed->value . "' THEN 1 ELSE 0 END) as completed_orders",
            "SUM(CASE WHEN orders.closed_at IS NULL THEN 1 ELSE 0 END) as active_orders",
            "SUM(orders.guest_count) as total_guests",
            "COUNT(DISTINCT orders.table_id) as assigned_tables",
            "SUM(op.quantity) as total_products",
            "SUM(orders.subtotal * $rate) as subtotal",
            "SUM(ot.amount * $rate) as tax",
            "SUM(orders.total * $rate) as total",
            "AVG(orders.total * $rate) as average_order_value",
            'AVG(CASE WHEN orders.closed_at IS NOT NULL THEN '.timestampDiffSql('MINUTE', 'orders.created_at', 'orders.closed_at').' ELSE NULL END) as average_service_minutes',
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
            "active_orders" => (int) $model->active_orders,
            "total_guests" => (int) $model->total_guests,
            "assigned_tables" => (int) $model->assigned_tables,
            "total_products" => (int) $model->total_products,
            "subtotal" => new Money($model->subtotal->amount(), $this->currency),
            "tax" => new Money($model->tax, $this->currency),
            "total" => new Money($model->total->amount(), $this->currency),
            "average_order_value" => new Money((float) $model->average_order_value, $this->currency),
            "average_service_minutes" => round((float) $model->average_service_minutes, 1),
        ];
    }

    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->withoutCanceledOrders()
                ->join(
                    DB::raw('(SELECT order_id, sum(quantity) quantity FROM order_products GROUP BY order_id) op'),
                    fn($join) => $join->on('orders.id', '=', 'op.order_id')
                )
                ->leftJoin(
                    DB::raw('(SELECT order_id, sum(amount) amount FROM order_taxes GROUP BY order_id) ot'),
                    fn($join) => $join->on('orders.id', '=', 'ot.order_id')
                )
                ->groupBy('orders.waiter_id'),
        ];
    }

    public function filters(Request $request): array
    {
        return [
            [
                "key" => 'status',
                "label" => __('report::reports.filters.order_status'),
                "type" => 'select',
                "options" => OrderStatus::toArrayTrans([
                    OrderStatus::Cancelled->value,
                    OrderStatus::Refunded->value,
                    OrderStatus::Merged->value,
                ]),
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
