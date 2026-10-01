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
use Modules\Support\Money;

class MenuEngineeringReport extends Report
{
    /** @inheritDoc */
    public function key(): string
    {
        return 'menu_engineering';
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            'product',
            'quantity',
            'total_sales',
            'gross_profit',
            'margin_percentage',
            'classification',
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
            "SUM((CAST(total AS DECIMAL(20,4)) - CAST(cost_price AS DECIMAL(20,4))) * {$rate}) as gross_profit",
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
        $sales = (float) $model->total_sales;
        $profit = (float) $model->gross_profit;
        $quantity = (float) $model->quantity;
        $margin = $sales > 0 ? round(($profit / $sales) * 100, 2) : 0.0;
        $classification = $this->classify($quantity, $margin);

        return [
            'product' => $model->product?->name ?? '-',
            'quantity' => round($quantity, 2),
            'total_sales' => $this->money($sales, $model->currency),
            'gross_profit' => $this->money($profit, $model->currency),
            'margin_percentage' => "{$margin}%",
            'classification' => __("report::reports.menu_engineering.classifications.{$classification}"),
            'recommendation' => __("report::reports.menu_engineering.recommendations.{$classification}"),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn (Builder $query, Closure $next) => $next($query)
                ->whereHas('order', fn (Builder $orderQuery) => $orderQuery->withoutCanceledOrders())
                ->without(['product', 'taxes', 'options'])
                ->groupBy('product_id', 'currency')
                ->havingRaw('SUM(quantity) > 0')
                ->orderByDesc('gross_profit'),
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
                'options' => OrderStatus::toArrayTrans(),
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

    private function classify(float $quantity, float $margin): string
    {
        $highVolume = $quantity >= (float) setting('menu_engineering_high_volume_quantity', 10);
        $highMargin = $margin >= (float) setting('menu_engineering_high_margin_percentage', 30);

        return match (true) {
            $highVolume && $highMargin => 'star',
            $highVolume && !$highMargin => 'workhorse',
            !$highVolume && $highMargin => 'puzzle',
            default => 'dog',
        };
    }

    private function money(float $amount, string $currency): Money
    {
        return $this->withRate
            ? Money::inDefaultCurrency($amount)
            : new Money($amount, $currency);
    }
}
