<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Aggregator\Enums\AggregatorProvider;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Payment\Enums\PaymentType;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class AggregatorPayoutReconciliationReport extends Report
{
    public function key(): string
    {
        return "aggregator_payout_reconciliation";
    }

    public function attributes(): Collection
    {
        return collect([
            "period",
            "provider",
            "total_orders",
            "gross_sales",
            "refunds",
            "net_sales",
            "payments_received",
            "pending_payout",
        ]);
    }

    public function columns(): array
    {
        $rate = $this->withRate ? 'orders.currency_rate' : '1';

        return [
            "MIN(orders.created_at) as start_date",
            "MAX(orders.created_at) as end_date",
            "aggregator_integrations.provider as provider",
            "COUNT(DISTINCT orders.id) as total_orders",
            "SUM(orders.total * $rate) as gross_sales",
            "SUM(COALESCE(payment_totals.refunds, 0)) as refunds",
            "SUM((orders.total * $rate) - COALESCE(payment_totals.refunds, 0)) as net_sales",
            "SUM(COALESCE(payment_totals.payments, 0)) as payments_received",
            "SUM(((orders.total * $rate) - COALESCE(payment_totals.refunds, 0)) - COALESCE(payment_totals.payments, 0)) as pending_payout",
        ];
    }

    public function model(): string
    {
        return Order::class;
    }

    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat(Carbon::parse($model->start_date), DateTimeFormat::Date) . " - " . dateTimeFormat(Carbon::parse($model->end_date), DateTimeFormat::Date),
            "provider" => AggregatorProvider::tryFrom($model->provider)?->trans() ?? $model->provider,
            "total_orders" => (int) $model->total_orders,
            "gross_sales" => new Money((float) $model->gross_sales, $this->currency),
            "refunds" => new Money((float) $model->refunds, $this->currency),
            "net_sales" => new Money((float) $model->net_sales, $this->currency),
            "payments_received" => new Money((float) $model->payments_received, $this->currency),
            "pending_payout" => new Money((float) $model->pending_payout, $this->currency),
        ];
    }

    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('aggregator_order_mappings', 'orders.id', '=', 'aggregator_order_mappings.order_id')
                ->join('aggregator_integrations', 'aggregator_order_mappings.aggregator_integration_id', '=', 'aggregator_integrations.id')
                ->leftJoin(
                    DB::raw($this->paymentTotalsSubquery()),
                    fn($join) => $join->on('orders.id', '=', 'payment_totals.order_id')
                )
                ->whereNotIn('orders.status', [
                    OrderStatus::Cancelled->value,
                    OrderStatus::Merged->value,
                ])
                ->groupBy('aggregator_integrations.provider'),
        ];
    }

    public function filters(Request $request): array
    {
        return [
            [
                "key" => "source",
                "label" => __("report::reports.filters.provider"),
                "type" => "select",
                "options" => AggregatorProvider::toArrayTrans(),
            ],
            [
                "key" => "status",
                "label" => __("report::reports.filters.order_status"),
                "type" => "select",
                "options" => OrderStatus::toArrayTrans([
                    OrderStatus::Cancelled->value,
                    OrderStatus::Merged->value,
                ]),
            ],
        ];
    }

    private function paymentTotalsSubquery(): string
    {
        $payment = PaymentType::Payment->value;
        $refund = PaymentType::Refund->value;

        return "(
            SELECT
                order_id,
                SUM(CASE WHEN type = '{$payment}' THEN amount * COALESCE(currency_rate, 1) ELSE 0 END) as payments,
                SUM(CASE WHEN type = '{$refund}' THEN amount * COALESCE(currency_rate, 1) ELSE 0 END) as refunds
            FROM payments
            WHERE deleted_at IS NULL
            GROUP BY order_id
        ) payment_totals";
    }

    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "orders.created_at";
    }
}
