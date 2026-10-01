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
use Modules\Support\Money;

class PeakHourSalesReport extends Report
{
    /** @inheritDoc */
    public function key(): string
    {
        return 'peak_hour_sales';
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            'hour',
            'total_orders',
            'total_products',
            'total_sales',
            'average_order_value',
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $rate = $this->withRate ? 'orders.currency_rate' : '1';

        return [
            'HOUR(orders.created_at) as hour',
            'MAX(orders.created_at) as created_at',
            'COUNT(*) as total_orders',
            'SUM(op.quantity) as total_products',
            "SUM(orders.total * {$rate}) as total_sales",
            "AVG(orders.total * {$rate}) as average_order_value",
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
        return [
            'hour' => $this->formatHour((int) $model->hour),
            'total_orders' => (int) $model->total_orders,
            'total_products' => (int) $model->total_products,
            'total_sales' => new Money((float) $model->total_sales, $this->currency),
            'average_order_value' => new Money((float) $model->average_order_value, $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn (Builder $query, Closure $next) => $next($query)
                ->withoutCanceledOrders()
                ->join(
                    DB::raw('(SELECT order_id, SUM(quantity) quantity FROM order_products GROUP BY order_id) op'),
                    fn ($join) => $join->on('orders.id', '=', 'op.order_id')
                )
                ->groupByRaw('HOUR(orders.created_at)')
                ->orderByDesc('total_sales'),
        ];
    }

    /** @inheritDoc */
    public function filters(Request $request): array
    {
        return [
            [
                'key' => 'status',
                'label' => __('report::reports.filters.order_status'),
                'type' => 'select',
                'options' => OrderStatus::toArrayTrans([
                    OrderStatus::Cancelled->value,
                    OrderStatus::Refunded->value,
                    OrderStatus::Merged->value,
                ]),
            ],
            [
                'key' => 'type',
                'label' => __('report::reports.filters.order_type'),
                'type' => 'select',
                'options' => OrderType::toArrayTrans(),
            ],
            [
                'key' => 'payment_status',
                'label' => __('report::reports.filters.payment_status'),
                'type' => 'select',
                'options' => OrderPaymentStatus::toArrayTrans(),
            ],
        ];
    }

    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = 'orders.created_at';
    }

    private function formatHour(int $hour): string
    {
        $start = sprintf('%02d:00', $hour);
        $end = sprintf('%02d:00', ($hour + 1) % 24);

        return "{$start} - {$end}";
    }
}
