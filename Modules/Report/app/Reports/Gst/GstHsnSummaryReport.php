<?php

namespace Modules\Report\Reports\Gst;

use Closure;
use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Order\Models\OrderProduct;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class GstHsnSummaryReport extends GstBaseReport
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "order_products.created_at";
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "gst_hsn_summary";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return OrderProduct::class;
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "hsn_code",
            "product_name",
            "uom",
            "total_qty",
            "taxable_value",
            "cgst_rate",
            "cgst_amount",
            "sgst_rate",
            "sgst_amount",
            "igst_rate",
            "igst_amount",
            "total_tax",
            "total_value",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $r = $this->withRate ? 'COALESCE(orders.currency_rate, 1)' : '1';

        return [
            "MIN(order_products.id) as id",
            "order_products.product_id",
            "COALESCE(p.hsn_code, 'N/A') as hsn_code",
            "MAX(p.name) as product_name",
            "SUM(order_products.quantity) as total_qty",
            "SUM(order_products.subtotal * $r) as taxable_value",
            "MAX(COALESCE(opt.cgst_rate, 0)) as cgst_rate",
            "SUM(COALESCE(opt.cgst_amount, 0) * $r) as cgst_amount",
            "MAX(COALESCE(opt.sgst_rate, 0)) as sgst_rate",
            "SUM(COALESCE(opt.sgst_amount, 0) * $r) as sgst_amount",
            "MAX(COALESCE(opt.igst_rate, 0)) as igst_rate",
            "SUM(COALESCE(opt.igst_amount, 0) * $r) as igst_amount",
            "SUM(COALESCE(opt.total_tax, 0) * $r) as total_tax",
            "SUM((order_products.subtotal + COALESCE(opt.total_tax, 0)) * $r) as total_value",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "hsn_code"     => $model->hsn_code,
            "product_name" => $model->product_name,
            "uom"          => "NOS",
            "total_qty"    => (float) $model->total_qty,
            "taxable_value" => new Money((float) $model->taxable_value, $this->currency),
            "cgst_rate"    => ((float) $model->cgst_rate) . '%',
            "cgst_amount"  => new Money((float) $model->cgst_amount, $this->currency),
            "sgst_rate"    => ((float) $model->sgst_rate) . '%',
            "sgst_amount"  => new Money((float) $model->sgst_amount, $this->currency),
            "igst_rate"    => ((float) $model->igst_rate) . '%',
            "igst_amount"  => new Money((float) $model->igst_amount, $this->currency),
            "total_tax"    => new Money((float) $model->total_tax, $this->currency),
            "total_value"  => new Money((float) $model->total_value, $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('orders', 'orders.id', '=', 'order_products.order_id')
                ->when($this->user->assignedToBranch(), fn(Builder $q) => $q->branchId($this->user->branch_id))
                ->whereNotIn('orders.status', $this->excludeCancelledStatuses())
                ->leftJoin('products as p', 'p.id', '=', 'order_products.product_id')
                ->leftJoin(DB::raw("(SELECT ot.order_product_id,
                    MAX(CASE WHEN t.gst_type = 'CGST' THEN t.rate ELSE 0 END) as cgst_rate,
                    SUM(CASE WHEN t.gst_type = 'CGST' THEN ot.amount ELSE 0 END) as cgst_amount,
                    MAX(CASE WHEN t.gst_type = 'SGST' THEN t.rate ELSE 0 END) as sgst_rate,
                    SUM(CASE WHEN t.gst_type = 'SGST' THEN ot.amount ELSE 0 END) as sgst_amount,
                    MAX(CASE WHEN t.gst_type = 'IGST' THEN t.rate ELSE 0 END) as igst_rate,
                    SUM(CASE WHEN t.gst_type = 'IGST' THEN ot.amount ELSE 0 END) as igst_amount,
                    SUM(ot.amount) as total_tax
                FROM order_taxes ot
                LEFT JOIN taxes t ON t.id = ot.tax_id
                WHERE ot.order_product_id IS NOT NULL
                GROUP BY ot.order_product_id) opt"), fn($j) => $j->on('opt.order_product_id', '=', 'order_products.id'))
                ->groupBy('order_products.product_id', 'p.hsn_code')
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
