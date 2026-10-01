<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Enums\LoyaltyRewardType;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\OrderProduct;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class FreeItemsCostReport extends LoyaltyReport
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "orders.created_at";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return OrderProduct::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_free_items_cost";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "product_name",
            "quantity",
            "cost_price",
            "total_cost",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $rate = $this->withRate ? 'order_products.currency_rate' : '1';

        return [
            "p.name as product_name",
            "SUM(order_products.quantity) as quantity",
            "COALESCE(SUM(order_products.cost_price * $rate), 0) as total_cost_raw",
            "COALESCE(SUM(order_products.cost_price * $rate) / NULLIF(SUM(order_products.quantity), 0), 0) as unit_cost_raw",
            "MIN(orders.created_at) as start_date",
            "MAX(orders.created_at) as end_date",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "product_name" => $model->product_name,
            "quantity" => (int)$model->quantity,
            "cost_price" => new Money($model->unit_cost_raw, $this->currency),
            "total_cost" => new Money($model->total_cost_raw, $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        $filters = $request->get('filters', []);

        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->without(["product", "taxes", "options"])
                ->join('orders', 'orders.id', '=', 'order_products.order_id')
                ->join('loyalty_gifts as lg', 'lg.id', '=', 'order_products.loyalty_gift_id')
                ->join('products as p', 'p.id', '=', 'order_products.product_id')
                ->where("orders.status", OrderStatus::Completed->value)
                ->where('lg.type', LoyaltyRewardType::FreeItem->value)
                ->when(isset($filters['loyalty_program_id']), fn($q) => $q->where('lg.loyalty_program_id', $filters['loyalty_program_id']))
                ->groupBy('order_products.product_id')
        ];
    }
}
