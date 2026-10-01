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
use Modules\Order\Models\OrderProduct;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class SlowMovingProductsReport extends Report
{
    /** @inheritDoc */
    public function key(): string
    {
        return 'slow_moving_products';
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            'product',
            'quantity',
            'total_sales',
            'last_sold_at',
            'recommendation',
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $rate = $this->withRate ? 'COALESCE(currency_rate, 1)' : '1';

        return [
            'product_id',
            'currency',
            'SUM(quantity) as quantity',
            "SUM(total * {$rate}) as total_sales",
            'MAX(order_products.created_at) as last_sold_at',
        ];
    }

    /** @inheritDoc */
    public function model(): string
    {
        return OrderProduct::class;
    }

    /** @inheritDoc */
    public function with(): array
    {
        return ['product' => fn ($query) => $query->select('id', 'name')->without(['branch'])];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            'product' => $model->product?->name ?? '-',
            'quantity' => round((float) $model->quantity, 2),
            'total_sales' => $this->money((float) $model->total_sales, $model->currency),
            'last_sold_at' => dateTimeFormat($model->last_sold_at, DateTimeFormat::Date),
            'recommendation' => __('report::reports.slow_moving_products.recommendation'),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        $threshold = max(0, (float) setting('slow_moving_products_quantity_threshold', 5));

        return [
            fn (Builder $query, Closure $next) => $next($query)
                ->whereHas('order', fn (Builder $orderQuery) => $orderQuery->withoutCanceledOrders())
                ->without(['product', 'taxes', 'options'])
                ->groupBy('product_id', 'currency')
                ->havingRaw('SUM(quantity) <= ?', [$threshold])
                ->orderBy('quantity'),
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

    /** @inheritDoc */
    public function hasSearch(): bool
    {
        return true;
    }

    private function money(float $amount, string $currency): Money
    {
        return $this->withRate
            ? Money::inDefaultCurrency($amount)
            : new Money($amount, $currency);
    }
}
