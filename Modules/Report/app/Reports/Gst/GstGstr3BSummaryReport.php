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

class GstGstr3BSummaryReport extends GstBaseReport
{
    /** @inheritDoc */
    public function key(): string
    {
        return "gst_gstr3b_summary";
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
            "month",
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
            "DATE_FORMAT(orders.created_at, '%Y-%m') as month",
            "COUNT(DISTINCT orders.id) as total_orders",
            $this->taxableAmountExpression() . " as taxable_amount",
            ...$this->gstSelectColumns(),
            "COALESCE(SUM(orders.total * $r), 0) as gross_total",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "month"          => $model->month,
            "total_orders"   => (int) $model->total_orders,
            "taxable_amount" => new Money((float) $model->taxable_amount, $this->currency),
            ...$this->gstMoneyResource($model),
            "gross_total"    => new Money((float) $model->gross_total, $this->currency),
        ];
    }

    /** @inheritDoc */
    protected function hasGroupByData(Request $request): bool
    {
        return true;
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->whereNotIn('orders.status', $this->excludeCancelledStatuses())
                ->when($this->user->assignedToBranch(), fn(Builder $q) => $q->where('orders.branch_id', $this->user->branch_id))
                ->leftJoin(DB::raw($this->gstPivotSubquery()), fn($j) => $j->on('orders.id', '=', 'gst_pivot.order_id'))
                ->groupByRaw("DATE_FORMAT(orders.created_at, '%Y-%m')")
                ->orderByRaw("DATE_FORMAT(orders.created_at, '%Y-%m') ASC")
        ];
    }

    /** @inheritDoc */
    public function filters(Request $request): array
    {
        return [
            [
                "key"     => 'type',
                "label"   => __('report::reports.filters.order_type'),
                "type"    => 'select',
                "options" => OrderType::toArrayTrans(),
            ],
        ];
    }
}
