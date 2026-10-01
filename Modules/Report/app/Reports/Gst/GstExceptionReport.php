<?php

namespace Modules\Report\Reports\Gst;

use Closure;
use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\OrderTax;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class GstExceptionReport extends GstBaseReport
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "order_taxes.created_at";
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "gst_exception_report";
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
            "date",
            "order_reference",
            "tax_name",
            "gst_type",
            "exception_type",
            "tax_amount",
            "status",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $r = $this->withRate ? 'order_taxes.currency_rate' : '1';

        return [
            "order_taxes.id",
            "order_taxes.created_at as tax_date",
            "orders.reference_no as order_reference",
            "order_taxes.name as tax_name",
            "taxes.gst_type",
            "order_taxes.amount * $r as tax_amount",
            "orders.status as order_status",
            "CASE
                WHEN order_taxes.amount < 0 THEN 'Negative Tax Amount'
                WHEN taxes.gst_type IS NULL THEN 'Unclassified GST Type'
                WHEN orders.status = '" . OrderStatus::Cancelled->value . "' THEN 'Tax on Cancelled Order'
                WHEN order_taxes.rate = 0 AND order_taxes.amount > 0 THEN 'Amount Without Rate'
                WHEN taxes.gst_type = 'IGST' AND (orders.gstin IS NULL OR orders.gstin = '') THEN 'Missing GSTIN on IGST Transaction'
                ELSE 'Rate Mismatch'
            END as exception_type",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "date"            => dateTimeFormat($model->tax_date, DateTimeFormat::Date),
            "order_reference" => $model->order_reference,
            "tax_name"        => $model->tax_name,
            "gst_type"        => $model->gst_type ?? 'Unclassified',
            "exception_type"  => $model->exception_type,
            "tax_amount"      => new Money((float) $model->tax_amount, $this->currency),
            "status"          => $model->order_status,
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->when($this->user->assignedToBranch(), fn(Builder $q) => $q->branchId($this->user->branch_id))
                ->join('orders', 'orders.id', '=', 'order_taxes.order_id')
                ->leftJoin('taxes', 'taxes.id', '=', 'order_taxes.tax_id')
                ->whereNull('order_taxes.order_product_id')
                ->where(fn(Builder $q) => $q
                    ->where('order_taxes.amount', '<', 0)
                    ->orWhereNull('taxes.gst_type')
                    ->orWhere('orders.status', OrderStatus::Cancelled->value)
                    ->orWhere(fn(Builder $q) => $q->where('order_taxes.rate', 0)->where('order_taxes.amount', '>', 0))
                    ->orWhere(fn(Builder $q) => $q
                        ->where('taxes.gst_type', 'IGST')
                        ->where(fn(Builder $q) => $q->whereNull('orders.gstin')->orWhere('orders.gstin', ''))
                    )
                )
        ];
    }

    /** @inheritDoc */
    public function filters(Request $request): array
    {
        return [];
    }

    /** @inheritDoc */
    public function hasSearch(): bool
    {
        return true;
    }
}
