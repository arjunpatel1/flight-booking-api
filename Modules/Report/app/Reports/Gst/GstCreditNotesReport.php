<?php

namespace Modules\Report\Reports\Gst;

use Closure;
use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class GstCreditNotesReport extends Report
{
    /** @inheritDoc */
    public function key(): string
    {
        return "gst_credit_notes";
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
            "order_type",
            "taxable_amount",
            "cgst",
            "sgst",
            "igst",
            "cess",
            "total",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $rate = $this->withRate ? 'COALESCE(orders.currency_rate, 1)' : '1';

        return [
            "orders.id",
            "orders.reference_no as reference",
            "orders.created_at as invoice_date",
            "orders.type as order_type_raw",
            "(orders.total - COALESCE(gst_cn.total_tax, 0)) * $rate as taxable_amount",
            "COALESCE(gst_cn.cgst, 0) * $rate as cgst",
            "COALESCE(gst_cn.sgst, 0) * $rate as sgst",
            "COALESCE(gst_cn.igst, 0) * $rate as igst",
            "COALESCE(gst_cn.cess, 0) * $rate as cess",
            "orders.total * $rate as total",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        $orderType = $model->order_type_raw;
        try {
            $orderType = OrderType::from($model->order_type_raw)->trans();
        } catch (\ValueError) {
        }

        return [
            "date"           => dateTimeFormat($model->invoice_date, DateTimeFormat::Date),
            "reference"      => $model->reference,
            "order_type"     => $orderType,
            "taxable_amount" => new Money((float) $model->taxable_amount, $this->currency),
            "cgst"           => new Money((float) $model->cgst, $this->currency),
            "sgst"           => new Money((float) $model->sgst, $this->currency),
            "igst"           => new Money((float) $model->igst, $this->currency),
            "cess"           => new Money((float) $model->cess, $this->currency),
            "total"          => new Money((float) $model->getRawOriginal('total'), $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->whereIn('orders.status', [
                    OrderStatus::Cancelled->value,
                    OrderStatus::Refunded->value,
                ])
                ->leftJoin(
                    DB::raw("(SELECT ot.order_id,
                        SUM(CASE WHEN t.gst_type = 'CGST' THEN ot.amount ELSE 0 END) as cgst,
                        SUM(CASE WHEN t.gst_type = 'SGST' THEN ot.amount ELSE 0 END) as sgst,
                        SUM(CASE WHEN t.gst_type = 'IGST' THEN ot.amount ELSE 0 END) as igst,
                        SUM(CASE WHEN t.gst_type = 'CESS' THEN ot.amount ELSE 0 END) as cess,
                        SUM(ot.amount) as total_tax
                    FROM order_taxes ot
                    LEFT JOIN taxes t ON t.id = ot.tax_id
                    GROUP BY ot.order_id) gst_cn"),
                    fn($join) => $join->on('orders.id', '=', 'gst_cn.order_id')
                )
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

    /** @inheritDoc */
    public function hasSearch(): bool
    {
        return true;
    }
}
