<?php

namespace Modules\Order\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Currency\Currency;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Events\OrderPaid;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Enums\PaymentType;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Services\PosCashMovement\PosCashMovementServiceInterface;
use Modules\Support\Money;

/**
 * Payment recording, due-amount and refund logic for the Order model.
 */
trait HasOrderPayments
{
    public function allowAddPayment(): bool
    {
        return !$this->payment_status->isPaid();
    }

    public function refreshDueAmount(): void
    {
        $scale = Currency::subunit($this->currency);
        $factor = 10 ** $scale;

        $totalPaymentsMinor = (int)round(
            $this->settledPaymentsQuery(PaymentType::Payment)->sum('amount') * $factor
        );

        $totalRefundsMinor = (int)round(
            $this->settledPaymentsQuery(PaymentType::Refund)->sum('amount') * $factor
        );

        $paidMinor = $totalPaymentsMinor - $totalRefundsMinor;

        $totalRounded = round($this->total->amount(), $scale);
        $totalMinor = (int)round($totalRounded * $factor);

        $dueMinor = max($totalMinor - $paidMinor, 0);

        if ($paidMinor <= 0) {
            $paymentStatus = OrderPaymentStatus::Unpaid;
        } elseif ($paidMinor < $totalMinor) {
            $paymentStatus = OrderPaymentStatus::PartiallyPaid;
        } else {
            $paymentStatus = OrderPaymentStatus::Paid;
        }

        $this->updateQuietly([
            'due_amount' => $dueMinor / $factor,
            'payment_status' => $paymentStatus,
            'payment_at' => $paymentStatus == OrderPaymentStatus::Paid ? now() : null,
        ]);

        if ($paymentStatus == OrderPaymentStatus::Paid && is_null($this->table_merge_id)) {
            DB::afterCommit(fn () => event(new OrderPaid($this->fresh())));
        }
    }

    public function hasRefundAmount(): bool
    {
        return $this->getRefundedAmount()->amount() > 0 && !$this->payment_status->isUnpaid();
    }

    public function getRefundedAmount(): Money
    {
        return $this->total->subtract($this->due_amount);
    }

    /**
     * Handle overpayment when an order is modified: refund overpaid amount,
     * then recalculate the due amount.
     */
    public function handleOverpaymentAdjustment(array $data): void
    {
        if ($data['overpaid_amount'] > 0) {
            $this->storePayment([
                'cashier_id' => auth()->id(),
                'method' => $data['refund_payment_method'],
                'amount' => $data['overpaid_amount'],
                'meta' => ['reason' => 'Order changed and overpaid'],
                'session' => PosSession::findOrFail($data['session_id']),
                'type' => PaymentType::Refund->value,
                'currency_rate' => $data['currency_rate'],
                'notes' => 'Order changed and overpaid',
            ]);
        }
        $this->refreshDueAmount();
    }

    public function storePayment(array $data): void
    {
        $type = $data['type'] ?? PaymentType::Payment;
        $typeValue = $type instanceof PaymentType ? $type->value : (string) $type;
        $status = $data['status'] ?? (
            $typeValue === PaymentType::Refund->value
                ? PaymentStatus::Refunded->value
                : PaymentStatus::Completed->value
        );

        $attributes = [
            "order_reference_no" => $this->reference_no,
            'branch_id' => $this->branch_id,
            'cashier_id' => $data['cashier_id'] ?? null,
            'transaction_id' => $data['transaction_id'] ?? null,
            'method' => $data['method'],
            'amount' => $data['amount'],
            'type' => $type,
            'currency' => $this->currency,
            'currency_rate' => $data['currency_rate'] ?? $this->currency_rate,
            'received_at' => $data['received_at'] ?? now(),
            'received_by' => $data['received_by'] ?? auth()->id(),
            'gateway' => $data['gateway'] ?? null,
            'gateway_transaction_id' => $data['gateway_transaction_id'] ?? null,
            'gateway_response' => $data['gateway_response'] ?? null,
            'processed_at' => $data['processed_at'] ?? null,
            'meta' => $data['meta'] ?? null,
        ];

        if ($this->paymentStatusColumnExists()) {
            $attributes['status'] = $status;
        }

        /** @var \Modules\Payment\Models\Payment $payment */
        $payment = $this->payments()->create($attributes);

        if ($data['method'] == PaymentMethod::Cash->value) {
            if ($payment->type == PaymentType::Refund) {
                app(PosCashMovementServiceInterface::class)
                    ->refundCash(
                        session: $data['session'],
                        amount: abs($data['amount']),
                        orderId: $this->id,
                        paymentId: $payment->id,
                        reference: $this->reference_no,
                        notes: $data['notes'] ?? null,
                    );
            } else {
                app(PosCashMovementServiceInterface::class)
                    ->sale(
                        session: $data['session'],
                        amount: abs($data['amount']),
                        orderId: $this->id,
                        paymentId: $payment->id,
                    );
            }
        }
    }

    private function settledPaymentsQuery(PaymentType $type)
    {
        $query = $this->payments()->where('type', $type);

        if (! $this->paymentStatusColumnExists()) {
            return $query;
        }

        $allowedStatuses = $type === PaymentType::Refund
            ? [PaymentStatus::Completed->value, PaymentStatus::Refunded->value]
            : [PaymentStatus::Completed->value];

        return $query->where(function (Builder $query) use ($allowedStatuses) {
            $query->whereNull('status')
                ->orWhereIn('status', $allowedStatuses);
        });
    }

    private function paymentStatusColumnExists(): bool
    {
        static $exists = null;

        return $exists ??= Schema::hasColumn('payments', 'status');
    }

    public function storePayments(array $payments): void
    {
        foreach ($payments as $payment) {
            $this->storePayment($payment);
        }

        $this->refreshDueAmount();
    }
}
