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

class GstCollectionReport extends Report
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "order_taxes.created_at";
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "gst_collection";
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
            "tax_name",
            "gst_type",
            "tax_rate",
            "total_entries",
            "total_collected",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $rate = $this->withRate ? 'order_taxes.currency_rate' : '1';

        return [
            "order_taxes.tax_id",
            "MAX(order_taxes.name) as tax_name",
            "taxes.gst_type",
            "taxes.rate as tax_rate",
            "COUNT(*) as total_entries",
            "SUM(order_taxes.amount * $rate) as total_collected",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "tax_name"        => $model->tax_name,
            "gst_type"        => $model->gst_type ?: 'Unclassified',
            "tax_rate"        => ((float) $model->tax_rate) . '%',
            "total_entries"   => (int) $model->total_entries,
            "total_collected" => new Money((float) $model->total_collected, $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->when($this->user->assignedToBranch(), fn(Builder $q) => $q->branchId($this->user->branch_id))
                ->whereNull("order_product_id")
                ->join('taxes', 'taxes.id', '=', 'order_taxes.tax_id')
                ->groupBy('order_taxes.tax_id', 'taxes.gst_type', 'taxes.rate')
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

    /** @inheritDoc */
    public function hasSearch(): bool
    {
        return true;
    }
}
