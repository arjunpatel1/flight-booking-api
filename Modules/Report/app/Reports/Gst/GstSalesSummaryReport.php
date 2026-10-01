<?php

namespace Modules\Report\Reports\Gst;

use Closure;
use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class GstSalesSummaryReport extends Report
{
    /** @inheritDoc */
    public function key(): string
    {
        return "gst_sales_summary";
    }

    /** @inheritDoc */
    public function render(Request $request): array
    {
        $payload = parent::render($request);

        $displayColumns = [
            'taxable_amount',
            'total_cgst',
            'total_sgst',
            'total_igst',
            'total_cess',
            'total_tax',
            'gross_total',
        ];

        $payload['headers'] = $payload['headers']->map(function (array $header) use ($displayColumns) {
            if (in_array($header['value'], $displayColumns, true)) {
                $header['value'] = $header['value'] . '_display';
            }

            $header['key'] = $header['value'];

            return $header;
        })->values();

        $payload['data'] = array_map(function (array $row) use ($displayColumns) {
            foreach ($displayColumns as $column) {
                $value = $row[$column] ?? null;
                $row[$column . '_display'] = $value instanceof Money
                    ? $value->format()
                    : ($value['formatted'] ?? '-');
                unset($row[$column]);
            }

            return $row;
        }, $payload['data']);

        return $payload;
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
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
    public function model(): string
    {
        return Order::class;
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $rate = $this->withRate ? 'COALESCE(orders.currency_rate, 1)' : '1';

        return [
            "MIN(orders.id) as id",
            "MIN(orders.created_at) as start_date",
            "MAX(orders.created_at) as end_date",
            "COUNT(DISTINCT orders.id) as total_orders",
            "COALESCE(SUM((orders.total - COALESCE(gst_pivot.total_tax, 0)) * $rate), 0) as taxable_amount",
            "COALESCE(SUM(COALESCE(gst_pivot.cgst, 0) * $rate), 0) as total_cgst",
            "COALESCE(SUM(COALESCE(gst_pivot.sgst, 0) * $rate), 0) as total_sgst",
            "COALESCE(SUM(COALESCE(gst_pivot.igst, 0) * $rate), 0) as total_igst",
            "COALESCE(SUM(COALESCE(gst_pivot.cess, 0) * $rate), 0) as total_cess",
            "COALESCE(SUM(COALESCE(gst_pivot.total_tax, 0) * $rate), 0) as total_tax",
            "COALESCE(SUM(orders.total * $rate), 0) as gross_total",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        $startDate = dateTimeFormat($model->start_date, DateTimeFormat::Date);
        $endDate   = dateTimeFormat($model->end_date, DateTimeFormat::Date);

        return [
            "id"            => (int) $model->id,
            "period"        => $startDate === $endDate ? $startDate : "$startDate - $endDate",
            "total_orders"  => (int) $model->total_orders,
            "taxable_amount" => new Money((float) $model->taxable_amount, $this->currency),
            "total_cgst"    => new Money((float) $model->total_cgst, $this->currency),
            "total_sgst"    => new Money((float) $model->total_sgst, $this->currency),
            "total_igst"    => new Money((float) $model->total_igst, $this->currency),
            "total_cess"    => new Money((float) $model->total_cess, $this->currency),
            "total_tax"     => new Money((float) $model->total_tax, $this->currency),
            "gross_total"   => new Money((float) $model->gross_total, $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->when(!array_key_exists('status', $request->get('filters', [])), function (Builder $q) {
                    $q->whereNotIn('orders.status', [
                        OrderStatus::Cancelled->value,
                        OrderStatus::Refunded->value,
                        OrderStatus::Merged->value,
                    ]);
                })
                ->leftJoin(
                    DB::raw("(SELECT ot.order_id,
                        SUM(CASE WHEN t.gst_type = 'CGST' THEN ot.amount ELSE 0 END) as cgst,
                        SUM(CASE WHEN t.gst_type = 'SGST' THEN ot.amount ELSE 0 END) as sgst,
                        SUM(CASE WHEN t.gst_type = 'IGST' THEN ot.amount ELSE 0 END) as igst,
                        SUM(CASE WHEN t.gst_type = 'CESS' THEN ot.amount ELSE 0 END) as cess,
                        SUM(ot.amount) as total_tax
                    FROM order_taxes ot
                    LEFT JOIN taxes t ON t.id = ot.tax_id
                    GROUP BY ot.order_id) gst_pivot"),
                    fn($join) => $join->on('orders.id', '=', 'gst_pivot.order_id')
                )
                ->when(!$this->hasGroupByData($request), fn($q) => $q->groupBy('orders.id'))
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
