<?php

namespace Modules\Report\Reports\Gst;

use Closure;
use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Models\Payment;
use Modules\Support\Money;

class GstPaymentMethodReport extends GstBaseReport
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "payments.created_at";
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "gst_payment_method";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Payment::class;
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "payment_method",
            "total_transactions",
            "taxable_amount",
            "total_tax",
            "total_paid",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $r = $this->withRate ? 'COALESCE(orders.currency_rate, 1)' : '1';

        return [
            "payments.method",
            "COUNT(DISTINCT payments.order_id) as total_transactions",
            $this->taxableAmountExpression('gst_ord') . " as taxable_amount",
            "COALESCE(SUM(COALESCE(gst_ord.total_tax, 0) * $r), 0) as total_tax",
            "COALESCE(SUM(payments.amount * $r), 0) as total_paid",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        $method = $model->method;
        if ($method instanceof PaymentMethod) {
            $method = $method->trans();
        } else {
            try {
                $method = PaymentMethod::from((string) $method)->trans();
            } catch (\ValueError) {}
        }

        return [
            "payment_method"     => $method,
            "total_transactions" => (int) $model->total_transactions,
            "taxable_amount"     => new Money((float) $model->taxable_amount, $this->currency),
            "total_tax"          => new Money((float) $model->total_tax, $this->currency),
            "total_paid"         => new Money((float) $model->total_paid, $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('orders', 'orders.id', '=', 'payments.order_id')
                ->when($this->user->assignedToBranch(), fn(Builder $q) => $q->where('orders.branch_id', $this->user->branch_id))
                ->leftJoin(
                    DB::raw("(SELECT ot.order_id, SUM(ot.amount) as total_tax
                        FROM order_taxes ot
                        GROUP BY ot.order_id) gst_ord"),
                    fn($j) => $j->on('orders.id', '=', 'gst_ord.order_id')
                )
                ->groupBy('payments.method')
        ];
    }

    /** @inheritDoc */
    public function filters(Request $request): array
    {
        return [];
    }
}
