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
use Modules\Support\Money;

class SalesByCreatorReport extends Report
{
    /** @inheritDoc */
    public function model(): string
    {
        return Order::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "sales_by_creator";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "creator",
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
            "orders.created_by",
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
        // With grouped queries, Eloquent hydrates a "model" but relationships may fail.
        // Access raw attributes directly and resolve the creator name from the DB.
        $creatorId = $model->getRawOriginal('created_by');
        $creatorName = __('report::reports.unassigned');

        if ($creatorId) {
            // Try to load via the relationship which may be null if the user was deleted
            try {
                $creatorName = $model->createdBy?->name ?? __('report::reports.unassigned');
            } catch (\Throwable) {
                // Fallback: query the user directly if the relationship fails
                try {
                    $user = \Modules\User\Models\User::query()
                        ->withOutGlobalBranchPermission()
                        ->withoutGlobalActive()
                        ->withTrashed()
                        ->find($creatorId);

                    $creatorName = $user?->name ?? __('report::reports.unassigned');
                } catch (\Throwable) {
                    $creatorName = __('report::reports.unassigned');
                }
            }
        }

        $startDate = dateTimeFormat($model->start_date, DateTimeFormat::Date);
        $endDate = dateTimeFormat($model->end_date, DateTimeFormat::Date);

        return [
            "period" => "$startDate - $endDate",
            "creator" => $creatorName,
            "total_orders" => (int) $model->total_orders,
            "total_products" => (int) $model->total_products,
            "subtotal" => new Money((float) ($model->subtotal?->amount() ?? 0), $this->currency),
            "tax" => new Money((float) ($model->tax ?? 0), $this->currency),
            "total" => new Money((float) ($model->total?->amount() ?? 0), $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->leftJoin(
                    DB::raw('(SELECT order_id, sum(quantity) quantity FROM order_products GROUP BY order_id) op'),
                    fn($join) => $join->on('orders.id', '=', 'op.order_id')
                )
                ->leftJoin(
                    DB::raw('(SELECT order_id, sum(amount) amount FROM order_taxes GROUP BY order_id) ot'),
                    fn($join) => $join->on('orders.id', '=', 'ot.order_id')
                )
                ->groupBy('orders.created_by')
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
            "createdBy:id,name",
        ];
    }
}