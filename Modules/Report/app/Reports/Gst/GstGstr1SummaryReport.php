<?php

namespace Modules\Report\Reports\Gst;

use Closure;
use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Order\Models\Order;
use Modules\Support\Money;

class GstGstr1SummaryReport extends GstBaseReport
{
    /** @inheritDoc */
    public function key(): string
    {
        return "gst_gstr1_summary";
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
            "supply_type",
            "total_invoices",
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
        $b2clThreshold = setting('gst.b2cl_threshold', 250000);

        return [
            "CASE
                WHEN orders.gstin IS NOT NULL AND orders.gstin != '' THEN 'B2B'
                WHEN (orders.total - COALESCE(gst_pivot.total_tax, 0)) >= $b2clThreshold THEN 'B2CL'
                ELSE 'B2CS'
            END as supply_type",
            "COUNT(DISTINCT orders.id) as total_invoices",
            $this->taxableAmountExpression() . " as taxable_amount",
            ...$this->gstSelectColumns(),
            "COALESCE(SUM(orders.total * $r), 0) as gross_total",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "supply_type"    => $model->supply_type,
            "total_invoices" => (int) $model->total_invoices,
            "taxable_amount" => new Money((float) $model->taxable_amount, $this->currency),
            ...$this->gstMoneyResource($model),
            "gross_total"    => new Money((float) $model->gross_total, $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        $b2clThreshold = setting('gst.b2cl_threshold', 250000);

        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->whereNotIn('orders.status', $this->excludeCancelledStatuses())
                ->when($this->user->assignedToBranch(), fn(Builder $q) => $q->where('orders.branch_id', $this->user->branch_id))
                ->leftJoin(DB::raw($this->gstPivotSubquery()), fn($j) => $j->on('orders.id', '=', 'gst_pivot.order_id'))
                ->groupBy(DB::raw("CASE
                    WHEN orders.gstin IS NOT NULL AND orders.gstin != '' THEN 'B2B'
                    WHEN (orders.total - COALESCE(gst_pivot.total_tax, 0)) >= $b2clThreshold THEN 'B2CL'
                    ELSE 'B2CS'
                END"))
        ];
    }

    /** @inheritDoc */
    public function filters(Request $request): array
    {
        return $this->standardFilters();
    }
}
