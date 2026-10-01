<?php

namespace Modules\Payment\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Models\Payment;
use Modules\Support\Money;

/**
 * Canonical, tenant/branch-scoped collection totals.
 *
 * Payment's HasBranch global scope is deliberately retained. Callers must not
 * disable it: tenant ownership is resolved from the authenticated actor and
 * permitted branches before any aggregation is performed.
 */
class PaymentAggregationService
{
    /** @param array<string,mixed> $filters */
    public function summarize(array $filters = []): array
    {
        $base = Payment::query()
            ->when($filters['branch_id'] ?? null, fn (Builder $query, $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['from'] ?? null, fn (Builder $query, $from) => $query->whereRaw('DATE('.$this->dateExpression().') >= ?', [$from]))
            ->when($filters['to'] ?? null, fn (Builder $query, $to) => $query->whereRaw('DATE('.$this->dateExpression().') <= ?', [$to]));

        $rows = (clone $base)
            ->whereIn('status', [PaymentStatus::Completed->value, PaymentStatus::Refunded->value])
            ->selectRaw('method')
            // A refunded payment was still collected originally. Include it in
            // gross and expose the refund separately so net subtracts it once.
            ->selectRaw("SUM(CASE WHEN status IN (?, ?) THEN amount * COALESCE(currency_rate, 1) ELSE 0 END) AS collected", [PaymentStatus::Completed->value, PaymentStatus::Refunded->value])
            ->selectRaw("SUM(CASE WHEN status = ? THEN amount * COALESCE(currency_rate, 1) ELSE 0 END) AS refunded", [PaymentStatus::Refunded->value])
            ->groupBy('method')
            ->get();

        $amounts = collect(PaymentMethod::cases())->mapWithKeys(fn (PaymentMethod $method) => [$method->value => 0.0])->all();
        $refunds = 0.0;

        foreach ($rows as $row) {
            $method = (string) $row->getRawOriginal('method');
            $amounts[$method] = ($amounts[$method] ?? 0.0) + (float) $row->collected;
            $refunds += (float) $row->refunded;
        }

        $gross = array_sum($amounts);
        $known = array_sum(array_intersect_key($amounts, array_flip(PaymentMethod::values())));

        return [
            'cash' => Money::inDefaultCurrency($amounts[PaymentMethod::Cash->value] ?? 0),
            'upi' => Money::inDefaultCurrency($amounts[PaymentMethod::UPI->value] ?? 0),
            'card' => Money::inDefaultCurrency($amounts[PaymentMethod::Card->value] ?? 0),
            'wallet' => Money::inDefaultCurrency($amounts[PaymentMethod::MobileWallet->value] ?? 0),
            'bank_transfer' => Money::inDefaultCurrency($amounts[PaymentMethod::BankTransfer->value] ?? 0),
            'other' => Money::inDefaultCurrency(max(0, $gross - $known)),
            'gross_collected' => Money::inDefaultCurrency($gross),
            'refunds' => Money::inDefaultCurrency($refunds),
            'net_collected' => Money::inDefaultCurrency($gross - $refunds),
            'basis' => 'payment_date',
        ];
    }

    private function dateExpression(): string
    {
        return 'COALESCE(received_at, processed_at, created_at)';
    }
}
