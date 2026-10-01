<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Payment\Enums\PaymentType;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class FinanceReconciliationReport extends Report
{
    public function key(): string
    {
        return 'finance_reconciliation';
    }

    public function attributes(): Collection
    {
        return collect([
            'period',
            'branch',
            'total_orders',
            'gross_sales',
            'tax_collected',
            'payments_received',
            'refunds',
            'cash_in',
            'cash_out',
            'aggregator_pending_payout',
            'net_cash_flow',
        ]);
    }

    public function columns(): array
    {
        $rate = $this->withRate ? 'orders.currency_rate' : '1';

        return [
            'orders.branch_id',
            'MIN(orders.created_at) as start_date',
            'MAX(orders.created_at) as end_date',
            'COUNT(DISTINCT orders.id) as total_orders',
            "SUM(orders.total * $rate) as gross_sales",
            'SUM(COALESCE(tax_totals.tax_collected, 0)) as tax_collected',
            'SUM(COALESCE(payment_totals.payments_received, 0)) as payments_received',
            'SUM(COALESCE(payment_totals.refunds, 0)) as refunds',
            'MAX(COALESCE(cash_totals.cash_in, 0)) as cash_in',
            'MAX(COALESCE(cash_totals.cash_out, 0)) as cash_out',
            "SUM(CASE WHEN aggregator_orders.order_id IS NOT NULL THEN ((orders.total * $rate) - COALESCE(payment_totals.refunds, 0) - COALESCE(payment_totals.payments_received, 0)) ELSE 0 END) as aggregator_pending_payout",
        ];
    }

    public function model(): string
    {
        return Order::class;
    }

    public function resource(Model $model): array
    {
        $cashIn = (float) $model->cash_in;
        $cashOut = (float) $model->cash_out;
        $paymentsReceived = (float) $model->payments_received;
        $refunds = (float) $model->refunds;

        return [
            'period' => dateTimeFormat(Carbon::parse($model->start_date), DateTimeFormat::Date) . ' - ' . dateTimeFormat(Carbon::parse($model->end_date), DateTimeFormat::Date),
            'branch' => $model->branch?->name ?? __('report::reports.unassigned'),
            'total_orders' => (int) $model->total_orders,
            'gross_sales' => new Money((float) $model->gross_sales, $this->currency),
            'tax_collected' => new Money((float) $model->tax_collected, $this->currency),
            'payments_received' => new Money($paymentsReceived, $this->currency),
            'refunds' => new Money($refunds, $this->currency),
            'cash_in' => new Money($cashIn, $this->currency),
            'cash_out' => new Money($cashOut, $this->currency),
            'aggregator_pending_payout' => new Money((float) $model->aggregator_pending_payout, $this->currency),
            'net_cash_flow' => new Money(($paymentsReceived + $cashIn) - ($refunds + $cashOut), $this->currency),
        ];
    }

    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->leftJoin(DB::raw($this->taxTotalsSubquery()), fn($join) => $join->on('orders.id', '=', 'tax_totals.order_id'))
                ->leftJoin(DB::raw($this->paymentTotalsSubquery($request)), fn($join) => $join->on('orders.id', '=', 'payment_totals.order_id'))
                ->leftJoin(DB::raw($this->cashTotalsSubquery($request)), fn($join) => $join->on('orders.branch_id', '=', 'cash_totals.branch_id'))
                ->leftJoin(DB::raw($this->aggregatorOrdersSubquery()), fn($join) => $join->on('orders.id', '=', 'aggregator_orders.order_id'))
                ->whereNotIn('orders.status', [
                    OrderStatus::Cancelled->value,
                    OrderStatus::Refunded->value,
                    OrderStatus::Merged->value,
                ])
                ->groupBy('orders.branch_id'),
        ];
    }

    public function with(): array
    {
        return ['branch:id,name'];
    }

    private function taxTotalsSubquery(): string
    {
        $rate = $this->withRate ? 'currency_rate' : '1';

        return "(
            SELECT
                order_id,
                SUM(amount * COALESCE($rate, 1)) as tax_collected
            FROM order_taxes
            GROUP BY order_id
        ) tax_totals";
    }

    private function paymentTotalsSubquery(Request $request): string
    {
        $payment = PaymentType::Payment->value;
        $refund = PaymentType::Refund->value;
        $where = $this->dateWhere($request, 'received_at');
        $rate = $this->withRate ? 'currency_rate' : '1';

        return "(
            SELECT
                order_id,
                SUM(CASE WHEN type = '{$payment}' THEN amount * COALESCE($rate, 1) ELSE 0 END) as payments_received,
                SUM(CASE WHEN type = '{$refund}' THEN amount * COALESCE($rate, 1) ELSE 0 END) as refunds
            FROM payments
            WHERE deleted_at IS NULL {$where}
            GROUP BY order_id
        ) payment_totals";
    }

    private function cashTotalsSubquery(Request $request): string
    {
        $where = $this->dateWhere($request, 'occurred_at');
        $rate = $this->withRate ? 'currency_rate' : '1';

        return "(
            SELECT
                branch_id,
                SUM(CASE WHEN direction = 'in' THEN amount * COALESCE($rate, 1) ELSE 0 END) as cash_in,
                SUM(CASE WHEN direction = 'out' THEN amount * COALESCE($rate, 1) ELSE 0 END) as cash_out
            FROM pos_cash_movements
            WHERE deleted_at IS NULL {$where}
            GROUP BY branch_id
        ) cash_totals";
    }

    private function aggregatorOrdersSubquery(): string
    {
        return '(
            SELECT DISTINCT order_id
            FROM aggregator_order_mappings
        ) aggregator_orders';
    }

    private function dateWhere(Request $request, string $column): string
    {
        $filters = $request->get('filters', []);
        $where = '';

        if (!empty($filters['from'])) {
            $where .= " AND DATE($column) >= '" . date('Y-m-d', strtotime($filters['from'])) . "'";
        }

        if (!empty($filters['to'])) {
            $where .= " AND DATE($column) <= '" . date('Y-m-d', strtotime($filters['to'])) . "'";
        }

        return $where;
    }

    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = 'orders.created_at';
    }
}
