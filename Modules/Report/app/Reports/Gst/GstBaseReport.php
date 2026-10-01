<?php

namespace Modules\Report\Reports\Gst;

use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Report\Report;
use Modules\Support\Money;

abstract class GstBaseReport extends Report
{
    protected function gstPivotSubquery(string $alias = 'gst_pivot'): string
    {
        return "(SELECT ot.order_id,
            SUM(CASE WHEN t.gst_type = 'CGST' THEN ot.amount ELSE 0 END) as cgst,
            SUM(CASE WHEN t.gst_type = 'SGST' THEN ot.amount ELSE 0 END) as sgst,
            SUM(CASE WHEN t.gst_type = 'IGST' THEN ot.amount ELSE 0 END) as igst,
            SUM(CASE WHEN t.gst_type = 'CESS' THEN ot.amount ELSE 0 END) as cess,
            SUM(ot.amount) as total_tax
        FROM order_taxes ot
        LEFT JOIN taxes t ON t.id = ot.tax_id
        GROUP BY ot.order_id) $alias";
    }

    protected function rateExpr(): string
    {
        return $this->withRate ? 'COALESCE(orders.currency_rate, 1)' : '1';
    }

    protected function gstSelectColumns(string $alias = 'gst_pivot'): array
    {
        $r = $this->rateExpr();
        return [
            "COALESCE(SUM(COALESCE({$alias}.cgst, 0) * $r), 0) as total_cgst",
            "COALESCE(SUM(COALESCE({$alias}.sgst, 0) * $r), 0) as total_sgst",
            "COALESCE(SUM(COALESCE({$alias}.igst, 0) * $r), 0) as total_igst",
            "COALESCE(SUM(COALESCE({$alias}.cess, 0) * $r), 0) as total_cess",
            "COALESCE(SUM(COALESCE({$alias}.total_tax, 0) * $r), 0) as total_tax",
        ];
    }

    protected function taxableAmountExpression(string $alias = 'gst_pivot'): string
    {
        $r = $this->rateExpr();

        return "COALESCE(SUM((orders.total - COALESCE({$alias}.total_tax, 0)) * $r), 0)";
    }

    protected function moneyAmount($model, string $key): float
    {
        $value = method_exists($model, 'getRawOriginal') ? $model->getRawOriginal($key) : ($model->{$key} ?? 0);

        return $value instanceof Money ? (float) $value->amount() : (float) $value;
    }

    protected function gstMoneyResource($model, ?string $currency = null): array
    {
        $cur = $currency ?? $this->currency;
        return [
            "total_cgst"  => new Money((float) $model->total_cgst, $cur),
            "total_sgst"  => new Money((float) $model->total_sgst, $cur),
            "total_igst"  => new Money((float) $model->total_igst, $cur),
            "total_cess"  => new Money((float) $model->total_cess, $cur),
            "total_tax"   => new Money((float) $model->total_tax, $cur),
        ];
    }

    protected function standardFilters(): array
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

    protected function excludeCancelledStatuses(): array
    {
        return [
            OrderStatus::Cancelled->value,
            OrderStatus::Refunded->value,
            OrderStatus::Merged->value,
        ];
    }
}
