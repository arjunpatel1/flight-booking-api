<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Discount\Models\Discount;
use Modules\Order\Enums\DiscountType;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\OrderDiscount;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;
use Modules\Voucher\Models\Voucher;

class DiscountsAndVouchersReport extends Report
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "order_discounts.created_at";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return OrderDiscount::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "discounts_and_vouchers";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "discount",
            "discount_type",
            "total_orders",
            "total_discount",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $rate = $this->withRate ? 'order_discounts.currency_rate' : '1';

        return [
            "order_discounts.discountable_type",
            "order_discounts.discountable_id",
            "order_discounts.type",
            "MIN(order_discounts.created_at) as start_date",
            "MAX(order_discounts.created_at) as end_date",
            "COUNT(DISTINCT order_discounts.order_id) as total_orders",
            "SUM(order_discounts.amount * $rate) as total_discount",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        $type = $model->type instanceof DiscountType ? $model->type : DiscountType::tryFrom($model->type);
        $discountable = $model->discountable;
        $name = match ($model->discountable_type) {
            Voucher::class => $discountable->code,
            Discount::class => $discountable->name,
            default => 'N/A',
        };

        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "discount" => $name,
            "discount_type" => $type->trans(),
            "total_orders" => (int)$model->total_orders,
            "total_discount" => new Money($model->total_discount, $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->when($this->user?->assignedToBranch(), fn(Builder $query) => $query->branchId($this->user->branch_id))
                ->groupBy('order_discounts.discountable_type', 'order_discounts.discountable_id', 'order_discounts.type'),
        ];
    }

    /** @inheritDoc */
    public function filters(Request $request): array
    {
        return [
            [
                "key" => 'status',
                "label" => __('report::reports.filters.order_status'),
                "type" => 'select',
                "options" => OrderStatus::toArrayTrans(),
            ],
            [
                "key" => 'type',
                "label" => __('report::reports.filters.order_type'),
                "type" => 'select',
                "options" => OrderType::toArrayTrans(),
            ],
            [
                "key" => 'payment_status',
                "label" => __('report::reports.filters.payment_status'),
                "type" => 'select',
                "options" => OrderPaymentStatus::toArrayTrans(),
            ],
            [
                "key" => 'discount_type',
                "label" => __('report::reports.filters.discount_type'),
                "type" => 'select',
                "options" => DiscountType::toArrayTrans(),
            ],
        ];
    }

    /** @inheritDoc */
    public function with(): array
    {
        return [
            "discountable",
        ];
    }

    /** @inheritDoc */
    public function hasSearch(): bool
    {
        return true;
    }
}
