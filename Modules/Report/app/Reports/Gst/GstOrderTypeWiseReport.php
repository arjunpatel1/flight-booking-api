<?php

namespace Modules\Report\Reports\Gst;

use Closure;
use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Support\Money;

class GstOrderTypeWiseReport extends GstBaseReport
{
    /** @inheritDoc */
    public function key(): string
    {
        return "gst_order_type_wise";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Order::class;
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "order_type",
            "total_orders",
            "taxable_amount",
            "total_cgst",
            "total_sgst",
            "total_igst",
            "total_cess",
            "total_tax",
            "gross_total",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $r = $this->rateExpr();

        return [
            "orders.type as order_type_raw",
            "COUNT(DISTINCT orders.id) as total_orders",
            $this->taxableAmountExpression() . " as taxable_amount",
            ...$this->gstSelectColumns(),
            "COALESCE(SUM(orders.total * $r), 0) as gross_total",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        $orderType = $model->order_type_raw;
        try { $orderType = OrderType::from($model->order_type_raw)->trans(); } catch (\ValueError) {}

        return [
            "order_type"     => $orderType,
            "total_orders"   => (int) $model->total_orders,
            "taxable_amount" => new Money((float) $model->taxable_amount, $this->currency),
            ...$this->gstMoneyResource($model),
            "gross_total"    => new Money((float) $model->gross_total, $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->whereNotIn('orders.status', $this->excludeCancelledStatuses())
                ->leftJoin(DB::raw($this->gstPivotSubquery()), fn($j) => $j->on('orders.id', '=', 'gst_pivot.order_id'))
                ->groupBy('orders.type')
        ];
    }

    /** @inheritDoc */
    public function filters(Request $request): array
    {
        return $this->standardFilters();
    }
}
