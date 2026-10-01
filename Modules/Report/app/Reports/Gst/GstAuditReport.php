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
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class GstAuditReport extends GstBaseReport
{
    /** @inheritDoc */
    public function key(): string
    {
        return "gst_audit_report";
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
            "issue_type",
            "severity",
            "total",
            "total_tax",
            "recommendation",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $r = $this->rateExpr();

        return [
            "orders.id",
            "orders.reference_no as reference",
            "orders.created_at as invoice_date",
            "orders.total * $r as total",
            "COALESCE(gst_pivot.total_tax, 0) * $r as total_tax",
            "(orders.total - COALESCE(gst_pivot.total_tax, 0)) * $r as subtotal",
            "CASE
                WHEN COALESCE(gst_pivot.total_tax, 0) < 0 THEN 'Negative Tax'
                WHEN COALESCE(gst_pivot.total_tax, 0) > orders.total THEN 'Tax Exceeds Invoice'
                WHEN (orders.total - COALESCE(gst_pivot.total_tax, 0)) > 0 AND COALESCE(gst_pivot.total_tax, 0) = 0 THEN 'Missing GST'
                WHEN orders.created_at > NOW() THEN 'Future-Dated Invoice'
                WHEN orders.gstin IS NOT NULL AND orders.gstin != '' AND LENGTH(orders.gstin) != 15 THEN 'Invalid GSTIN Format'
                WHEN orders.reference_no IN (SELECT dup.reference_no FROM orders dup GROUP BY dup.reference_no HAVING COUNT(dup.id) > 1) THEN 'Duplicate Invoice Number'
                ELSE 'Other Anomaly'
            END as issue_type",
            "CASE
                WHEN COALESCE(gst_pivot.total_tax, 0) < 0 THEN 'Critical'
                WHEN COALESCE(gst_pivot.total_tax, 0) > orders.total THEN 'Critical'
                WHEN orders.gstin IS NOT NULL AND orders.gstin != '' AND LENGTH(orders.gstin) != 15 THEN 'High'
                WHEN orders.reference_no IN (SELECT dup.reference_no FROM orders dup GROUP BY dup.reference_no HAVING COUNT(dup.id) > 1) THEN 'High'
                WHEN (orders.total - COALESCE(gst_pivot.total_tax, 0)) > 0 AND COALESCE(gst_pivot.total_tax, 0) = 0 THEN 'High'
                WHEN orders.created_at > NOW() THEN 'Medium'
                ELSE 'Medium'
            END as severity",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        $recommendations = [
            'Missing GST'            => 'Verify tax configuration for this order type.',
            'Negative Tax'           => 'Investigate data integrity — negative tax values are invalid.',
            'Tax Exceeds Invoice'    => 'Review order total calculation — tax cannot exceed invoice amount.',
            'Future-Dated Invoice'   => 'Invoice date is in the future — check system clock or manual date entry.',
            'Invalid GSTIN Format'   => 'GSTIN must be exactly 15 characters. Verify buyer GSTIN before filing.',
            'Duplicate Invoice Number' => 'Duplicate invoice reference detected — investigate data entry or import.',
            'Other Anomaly'          => 'Review this order for data integrity issues.',
        ];

        return [
            "date"           => dateTimeFormat($model->invoice_date, DateTimeFormat::Date),
            "reference"      => $model->reference,
            "issue_type"     => $model->issue_type,
            "severity"       => $model->severity,
            "total"          => new Money((float) $model->getRawOriginal('total'), $this->currency),
            "total_tax"      => new Money((float) $model->total_tax, $this->currency),
            "recommendation" => $recommendations[$model->issue_type] ?? 'Review this order.',
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->when($this->user->assignedToBranch(), fn(Builder $q) => $q->where('orders.branch_id', $this->user->branch_id))
                ->leftJoin(DB::raw($this->gstPivotSubquery()), fn($j) => $j->on('orders.id', '=', 'gst_pivot.order_id'))
                ->where(fn(Builder $q) => $q
                    ->where(fn(Builder $q) => $q->whereRaw('(orders.total - COALESCE(gst_pivot.total_tax, 0)) > 0')->whereRaw('COALESCE(gst_pivot.total_tax, 0) = 0'))
                    ->orWhereRaw('COALESCE(gst_pivot.total_tax, 0) < 0')
                    ->orWhereRaw('COALESCE(gst_pivot.total_tax, 0) > orders.total')
                    ->orWhereRaw('orders.created_at > NOW()')
                    ->orWhereRaw("orders.gstin IS NOT NULL AND orders.gstin != '' AND LENGTH(orders.gstin) != 15")
                    ->orWhereRaw("orders.reference_no IN (SELECT dup.reference_no FROM orders dup GROUP BY dup.reference_no HAVING COUNT(dup.id) > 1)")
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
