<?php

namespace Modules\Report\Reports;

use Closure;
use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class SalesByCashierReport extends Report
{
    /** @inheritDoc */
    public function model(): string
    {
        return Order::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "sales_by_cashier";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "date",
            "cashier",
            "total_orders",
            "total_products",
            "subtotal",
            "tax",
            "total"
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $rate = $this->withRate ? 'orders.currency_rate' : '1';

        return [
            "orders.cashier_id",
            "COUNT(*) as total_orders",
            "SUM(op.quantity) as total_products",
            "SUM(orders.subtotal * $rate) as subtotal",
            "SUM(ot.amount * $rate) as tax",
            "SUM(orders.total * $rate) as total",
            "MIN(orders.created_at) as start_date",
            "MAX(orders.created_at) as end_date",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "date" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "cashier" => $model->cashier->name,
            "total_orders" => (int)$model->total_orders,
            "total_products" => (int)$model->total_products,
            "subtotal" => new Money($model->subtotal->amount(), $this->currency),
            "tax" => new Money((float) ($model->tax ?? 0), $this->currency),
            "total" => new Money($model->total->amount(), $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->whereNotNull("cashier_id")
                ->withoutCanceledOrders()
                ->where("payment_status", OrderPaymentStatus::Paid)
                ->join(
                    DB::raw('(SELECT order_id, sum(quantity) quantity FROM order_products GROUP BY order_id) op'),
                    fn($join) => $join->on('orders.id', '=', 'op.order_id')
                )
                ->leftJoin(
                    DB::raw('(SELECT order_id, sum(amount) amount FROM order_taxes GROUP BY order_id) ot'),
                    fn($join) => $join->on('orders.id', '=', 'ot.order_id')
                )
                ->groupBy('orders.cashier_id')
        ];
    }

    /** @inheritDoc */
    public function filters(Request $request): array
    {
        return [
            [
                "key" => 'type',
                "label" => __('report::reports.filters.order_type'),
                "type" => 'select',
                "options" => OrderType::toArrayTrans(),
            ],
        ];
    }

    /** @inheritDoc */
    public function with(): array
    {
        return [
            "cashier:id,name",
        ];
    }
}
