<?php

namespace Modules\Invoice\Services\PaymentAllocation;


use Illuminate\Support\Collection;
use Modules\Invoice\Models\Invoice;
use Modules\Payment\Enums\PaymentType;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentAllocation;

class PaymentAllocationService implements PaymentAllocationServiceInterface
{
    /** @inheritDoc */
    public function allocate(Invoice $invoice, Collection $orders): void
    {
        $payments = $this->collectPayments($orders);

        /** @var Payment $payment */
        foreach ($payments as $payment) {
            PaymentAllocation::query()->create([
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'currency' => $payment->currency,
                'currency_rate' => $payment->currency_rate,
                'amount' => $payment->amount,
            ]);
        }

        $this->updateInvoicePaymentTotals($invoice);
    }

    /**
     * Collect payments from order or merged orders
     *
     * @param Collection $orders
     * @return Collection
     */
    private function collectPayments(Collection $orders): Collection
    {
        $all = collect();

        foreach ($orders as $order) {
            foreach ($order->payments as $payment) {
                $all->push($payment);
            }
        }

        return $all;
    }

    /**
     * Update invoice cached totals
     *
     * @param Invoice $invoice
     * @return void
     */
    private function updateInvoicePaymentTotals(Invoice $invoice): void
    {
        $paid = $invoice->allocations()
            ->whereHas("payment", fn($query) => $query->where('type', PaymentType::Payment))
            ->sum('amount');
        $refunded = $invoice->allocations()
            ->whereHas("payment", fn($query) => $query->where('type', PaymentType::Refund))
            ->sum('amount');

        $net = $paid - $refunded;

        $invoice->update([
            'paid_amount' => $paid,
            'refunded_amount' => $refunded,
            'net_paid' => $net,
        ]);
    }
}
