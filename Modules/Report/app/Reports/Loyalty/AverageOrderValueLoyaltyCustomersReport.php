<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Enums\LoyaltyTransactionType;
use Modules\Order\Models\Order;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class AverageOrderValueLoyaltyCustomersReport extends LoyaltyReport
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "orders.created_at";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Order::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_average_order_value_loyalty_customers";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "total_orders",
            "average_order_value",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $rate = $this->withRate ? 'COALESCE(orders.currency_rate, 1)' : '1';

        return [
            "COUNT(DISTINCT orders.id) as total_orders",
            "COALESCE(SUM(orders.total * $rate), 0) as revenue_raw",
            "MIN(orders.created_at) as start_date",
            "MAX(orders.created_at) as end_date",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {

        $avg = $model->total_orders > 0
            ? $model->revenue_raw / $model->total_orders
            : 0;

        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "total_orders" => (int)$model->total_orders,
            "average_order_value" => new Money($avg, $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        $filters = $request->get('filters', []);

        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->from('orders')
                ->join('loyalty_transactions as lt', function ($join) {
                    $join->on('lt.order_id', '=', 'orders.id')
                        ->where('lt.type', LoyaltyTransactionType::Earn->value);
                })
                ->join('loyalty_customers as lc', 'lc.id', '=', 'lt.loyalty_customer_id')
                ->when(isset($filters['loyalty_program_id']), fn($q) => $q->where('lc.loyalty_program_id', $filters['loyalty_program_id']))
        ];
    }
}
