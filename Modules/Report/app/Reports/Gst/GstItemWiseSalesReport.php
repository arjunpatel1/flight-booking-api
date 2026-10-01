<?php

namespace Modules\Report\Reports\Gst;

use Closure;
use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Order\Models\OrderProduct;
use Modules\Support\Money;

class GstItemWiseSalesReport extends GstBaseReport
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "order_products.created_at";
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "gst_item_wise_sales";
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
            "product_name",
            "hsn_code",
            "total_qty",
            "unit_price",
            "taxable_value",
            "total_cgst",
            "total_sgst",
            "total_igst",
            "total_cess",
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
            "MAX(p.name) as product_name",
            "COALESCE(MAX(p.hsn_code), 'N/A') as hsn_code",
            "SUM(order_products.quantity) as total_qty",
            "MAX(order_products.unit_price) as unit_price",
            "COALESCE(SUM(order_products.subtotal * $r), 0) as taxable_value",
            "COALESCE(SUM(CASE WHEN t.gst_type = 'CGST' THEN ot.amount * $r ELSE 0 END), 0) as total_cgst",
            "COALESCE(SUM(CASE WHEN t.gst_type = 'SGST' THEN ot.amount * $r ELSE 0 END), 0) as total_sgst",
            "COALESCE(SUM(CASE WHEN t.gst_type = 'IGST' THEN ot.amount * $r ELSE 0 END), 0) as total_igst",
            "COALESCE(SUM(CASE WHEN t.gst_type = 'CESS' THEN ot.amount * $r ELSE 0 END), 0) as total_cess",
            "COALESCE(SUM(ot.amount * $r), 0) as total_tax",
            "COALESCE(SUM((order_products.subtotal + COALESCE(ot.amount, 0)) * $r), 0) as total_value",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "product_name"  => $model->product_name,
            "hsn_code"      => $model->hsn_code,
            "total_qty"     => (float) $model->total_qty,
            "unit_price"    => new Money($this->moneyAmount($model, 'unit_price'), $this->currency),
            "taxable_value" => new Money((float) $model->taxable_value, $this->currency),
            "total_cgst"    => new Money((float) $model->total_cgst, $this->currency),
            "total_sgst"    => new Money((float) $model->total_sgst, $this->currency),
            "total_igst"    => new Money((float) $model->total_igst, $this->currency),
            "total_cess"    => new Money((float) $model->total_cess, $this->currency),
            "total_tax"     => new Money((float) $model->total_tax, $this->currency),
            "total_value"   => new Money((float) $model->total_value, $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('orders', 'orders.id', '=', 'order_products.order_id')
                ->when($this->user->assignedToBranch(), fn(Builder $q) => $q->where('orders.branch_id', $this->user->branch_id))
                ->whereNotIn('orders.status', $this->excludeCancelledStatuses())
                ->leftJoin('products as p', 'p.id', '=', 'order_products.product_id')
                ->leftJoin('order_taxes as ot', 'ot.order_product_id', '=', 'order_products.id')
                ->leftJoin('taxes as t', 't.id', '=', 'ot.tax_id')
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
