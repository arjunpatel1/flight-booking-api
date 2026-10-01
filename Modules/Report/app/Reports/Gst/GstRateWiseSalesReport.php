<?php

namespace Modules\Report\Reports\Gst;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\OrderTax;
use Modules\Report\Report;
use Modules\Support\Money;

class GstRateWiseSalesReport extends Report
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "order_taxes.created_at";
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "gst_rate_wise_sales";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return OrderTax::class;
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "tax_rate",
            "total_orders",
            "cgst",
            "sgst",
            "igst",
            "cess",
            "total_gst",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $rate = $this->withRate ? 'order_taxes.currency_rate' : '1';

        return [
            "order_taxes.rate as tax_rate",
            "COUNT(DISTINCT order_taxes.order_id) as total_orders",
            "SUM(CASE WHEN taxes.gst_type = 'CGST' THEN order_taxes.amount * $rate ELSE 0 END) as cgst",
            "SUM(CASE WHEN taxes.gst_type = 'SGST' THEN order_taxes.amount * $rate ELSE 0 END) as sgst",
            "SUM(CASE WHEN taxes.gst_type = 'IGST' THEN order_taxes.amount * $rate ELSE 0 END) as igst",
            "SUM(CASE WHEN taxes.gst_type = 'CESS' THEN order_taxes.amount * $rate ELSE 0 END) as cess",
            "SUM(order_taxes.amount * $rate) as total_gst",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "tax_rate"     => ((float) $model->tax_rate) . '%',
            "total_orders" => (int) $model->total_orders,
            "cgst"         => new Money((float) $model->cgst, $this->currency),
            "sgst"         => new Money((float) $model->sgst, $this->currency),
            "igst"         => new Money((float) $model->igst, $this->currency),
            "cess"         => new Money((float) $model->cess, $this->currency),
            "total_gst"    => new Money((float) $model->total_gst, $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->when($this->user->assignedToBranch(), fn(Builder $q) => $q->branchId($this->user->branch_id))
                ->join('taxes', 'taxes.id', '=', 'order_taxes.tax_id')
                ->groupBy('order_taxes.rate')
        ];
    }

    /** @inheritDoc */
    public function filters(Request $request): array
    {
        return [
            [
                "key"     => 'status',
                "label"   => __('report::reports.filters.order_status'),
                "type"    => 'select',
                "options" => OrderStatus::toArrayTrans(),
            ],
            [
                "key"     => 'type',
                "label"   => __('report::reports.filters.order_type'),
                "type"    => 'select',
                "options" => OrderType::toArrayTrans(),
            ],
            [
                "key"     => 'payment_status',
                "label"   => __('report::reports.filters.payment_status'),
                "type"    => 'select',
                "options" => OrderPaymentStatus::toArrayTrans(),
            ],
        ];
    }
}
