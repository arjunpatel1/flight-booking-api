<?php

namespace Modules\Report\Reports\Gst;

use Closure;
use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Order\Models\Order;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class GstB2BSalesReport extends GstBaseReport
{
    /** @inheritDoc */
    public function key(): string
    {
        return "gst_b2b_sales";
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
            "date",
            "reference",
            "gstin",
            "customer_name",
            "order_type",
            "taxable_amount",
            "total_cgst",
            "total_sgst",
            "total_igst",
            "total_cess",
            "total_tax",
            "total",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $r = $this->rateExpr();

        return [
            "orders.id",
            "orders.reference_no as reference",
            "orders.gstin",
            "orders.customer_gstin_name as customer_name",
            "orders.created_at as invoice_date",
            "orders.type as order_type_raw",
            "(orders.total - COALESCE(gst_pivot.total_tax, 0)) * $r as taxable_amount",
            "COALESCE(gst_pivot.cgst, 0) * $r as total_cgst",
            "COALESCE(gst_pivot.sgst, 0) * $r as total_sgst",
            "COALESCE(gst_pivot.igst, 0) * $r as total_igst",
            "COALESCE(gst_pivot.cess, 0) * $r as total_cess",
            "COALESCE(gst_pivot.total_tax, 0) * $r as total_tax",
            "orders.total * $r as total",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "date"           => dateTimeFormat($model->invoice_date, DateTimeFormat::Date),
            "reference"      => $model->reference,
            "gstin"          => $model->gstin,
            "customer_name"  => $model->customer_name,
            "order_type"     => $model->order_type_raw,
            "taxable_amount" => new Money((float) $model->taxable_amount, $this->currency),
            "total_cgst"     => new Money((float) $model->total_cgst, $this->currency),
            "total_sgst"     => new Money((float) $model->total_sgst, $this->currency),
            "total_igst"     => new Money((float) $model->total_igst, $this->currency),
            "total_cess"     => new Money((float) $model->total_cess, $this->currency),
            "total_tax"      => new Money((float) $model->total_tax, $this->currency),
            "total"          => new Money((float) $model->getRawOriginal('total'), $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->whereNotIn('orders.status', $this->excludeCancelledStatuses())
                ->whereNotNull('orders.gstin')
                ->where('orders.gstin', '!=', '')
                ->leftJoin(DB::raw($this->gstPivotSubquery()), fn($j) => $j->on('orders.id', '=', 'gst_pivot.order_id'))
        ];
    }

    /** @inheritDoc */
    public function filters(Request $request): array
    {
        return $this->standardFilters();
    }

    /** @inheritDoc */
    public function hasSearch(): bool
    {
        return true;
    }
}
