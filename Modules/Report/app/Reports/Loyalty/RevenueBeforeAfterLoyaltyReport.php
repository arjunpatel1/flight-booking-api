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

class RevenueBeforeAfterLoyaltyReport extends LoyaltyReport
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
        return "loyalty_revenue_before_after_loyalty";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "revenue_before",
            "revenue_after",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $rate = $this->withRate ? 'COALESCE(orders.currency_rate, 1)' : '1';

        return [
            "COALESCE(SUM(CASE WHEN lt.id IS NULL THEN orders.total * $rate ELSE 0 END), 0) as revenue_before_raw",
            "COALESCE(SUM(CASE WHEN lt.id IS NOT NULL THEN orders.total * $rate ELSE 0 END), 0) as revenue_after_raw",
            "MIN(orders.created_at) as start_date",
            "MAX(orders.created_at) as end_date",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "revenue_before" => new Money($model->revenue_before_raw, $this->currency),
            "revenue_after" => new Money($model->revenue_after_raw, $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        $filters = $request->get('filters', []);

        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->from('orders')
                ->leftJoin('loyalty_transactions as lt', function ($join) {
                    $join->on('lt.order_id', '=', 'orders.id')
                        ->where('lt.type', LoyaltyTransactionType::Earn->value);
                })
                ->leftJoin('loyalty_customers as lc', 'lc.id', '=', 'lt.loyalty_customer_id')
                ->when(isset($filters['loyalty_program_id']), fn($q) => $q->where('lc.loyalty_program_id', $filters['loyalty_program_id']))
        ];
    }
}
