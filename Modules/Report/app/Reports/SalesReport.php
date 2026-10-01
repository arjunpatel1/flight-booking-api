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
use Modules\Order\Enums\OrderSourceFilter;
use Modules\Order\Models\Order;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class SalesReport extends Report
{
    /** @inheritDoc */
    public function key(): string
    {
        return "sales";
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
        $rate = $this->withRate ? 'COALESCE(orders.currency_rate, 1)' : '1';

        return [
            "MIN(orders.created_at) as start_date",
            "MAX(orders.created_at) as end_date",
            "COUNT(DISTINCT orders.id) as total_orders",
            "COALESCE(SUM(op.quantity), 0) as total_products",
            "COALESCE(SUM(orders.subtotal * $rate), 0) as subtotal",
            "COALESCE(SUM(ot.amount * $rate), 0) as tax",
            "COALESCE(SUM(orders.total * $rate), 0) as total"
        ];
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Order::class;
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        $startDate = dateTimeFormat($model->start_date, DateTimeFormat::Date);
        $endDate = dateTimeFormat($model->end_date, DateTimeFormat::Date);

        return [
            "period" => $startDate === $endDate ? $startDate : "$startDate - $endDate",
            "total_orders" => (int)$model->total_orders,
            "total_products" => (int)$model->total_products,
            "subtotal" => new Money($model->subtotal?->amount() ?: 0, $this->currency),
            "tax" => new Money($model->tax ?: 0, $this->currency),
            "total" => new Money($model->total?->amount() ?: 0, $this->currency),
        ];
    }

    /** @inheritDoc */
    public function render(Request $request): array
    {
        return [
            ...parent::render($request),
            "analytics" => $this->analytics($request),
        ];
    }

    private function analytics(Request $request): array
    {
        $rows = $this->data($request, false);
        $totalOrders = (int) $rows->sum('total_orders');
        $totalProducts = (int) $rows->sum('total_products');
        $subtotal = (float) $rows->sum(fn(array $row) => $row['subtotal']->amount());
        $tax = (float) $rows->sum(fn(array $row) => $row['tax']->amount());
        $total = (float) $rows->sum(fn(array $row) => $row['total']->amount());
        $averageOrderValue = $totalOrders > 0 ? $total / $totalOrders : 0;

        return [
            [
                "key" => "total_sales",
                "label" => __("report::attributes.sales.total"),
                "value" => new Money($total, $this->currency),
                "icon" => "tabler-currency-rupee",
                "color" => "primary",
            ],
            [
                "key" => "total_orders",
                "label" => __("report::attributes.sales.total_orders"),
                "value" => $totalOrders,
                "icon" => "tabler-receipt-2",
                "color" => "success",
            ],
            [
                "key" => "total_products",
                "label" => __("report::attributes.sales.total_products"),
                "value" => $totalProducts,
                "icon" => "tabler-package",
                "color" => "info",
            ],
            [
                "key" => "tax",
                "label" => __("report::attributes.sales.tax"),
                "value" => new Money($tax, $this->currency),
                "icon" => "tabler-tax",
                "color" => "warning",
            ],
            [
                "key" => "subtotal",
                "label" => __("report::attributes.sales.subtotal"),
                "value" => new Money($subtotal, $this->currency),
                "icon" => "tabler-calculator",
                "color" => "secondary",
            ],
            [
                "key" => "average_order_value",
                "label" => __("report::attributes.sales.average_order_value"),
                "value" => new Money($averageOrderValue, $this->currency),
                "icon" => "tabler-chart-donut-3",
                "color" => "error",
            ],
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->when(!array_key_exists('status', $request->get('filters', [])), function (Builder $query) {
                    $query->whereNotIn('orders.status', [
                        OrderStatus::Cancelled->value,
                        OrderStatus::Refunded->value,
                        OrderStatus::Merged->value,
                    ]);
                })
                ->leftJoin(
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
            [
                "key" => 'source',
                "label" => __('report::reports.filters.order_source'),
                "type" => 'select',
                "options" => OrderSourceFilter::toArrayTrans(),
            ],
        ];
    }
}
