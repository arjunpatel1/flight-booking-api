<?php

namespace Modules\Pos\Http\Controllers\Api\V1\Concerns;

use Illuminate\Support\Facades\Schema;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Models\Payment;

trait BuildsRecoveryPaymentSummary
{
    private function paymentSummary(?int $branchId): array
    {
        if (! Schema::hasTable('payments')) {
            return [
                'storage_ready' => false,
                'summary' => [
                    'payment_pending' => 0,
                    'payment_failed' => 0,
                    'payment_gateway_pending' => 0,
                    'payment_gateway_failed' => 0,
                ],
                'items' => [],
            ];
        }

        $hasStatus = Schema::hasColumn('payments', 'status');
        $hasGateway = Schema::hasColumn('payments', 'gateway');
        $hasGatewayResponse = Schema::hasColumn('payments', 'gateway_response');
        $hasProcessedAt = Schema::hasColumn('payments', 'processed_at');
        $baseQuery = Payment::query()
            ->with(['order:id,reference_no,status,payment_status,total', 'cashier:id,name'])
            ->when($branchId, fn($query) => $query->where('branch_id', $branchId));

        $pending = $hasStatus ? (clone $baseQuery)->where('status', PaymentStatus::Pending->value)->count() : 0;
        $failed = $hasStatus ? (clone $baseQuery)->where('status', PaymentStatus::Failed->value)->count() : 0;
        $gatewayPending = $hasGateway && $hasProcessedAt
            ? (clone $baseQuery)->whereNotNull('gateway')->whereNull('processed_at')->count()
            : 0;
        $gatewayFailed = $hasGateway && $hasStatus
            ? (clone $baseQuery)->whereNotNull('gateway')->where('status', PaymentStatus::Failed->value)->count()
            : 0;

        $items = $this->paymentIssueItems(
            $baseQuery,
            $hasStatus,
            $hasGateway,
            $hasGatewayResponse,
            $hasProcessedAt
        );

        return [
            'storage_ready' => true,
            'summary' => [
                'payment_pending' => $pending,
                'payment_failed' => $failed,
                'payment_gateway_pending' => $gatewayPending,
                'payment_gateway_failed' => $gatewayFailed,
            ],
            'items' => $items,
        ];
    }

    private function paymentIssueItems(
        mixed $baseQuery,
        bool $hasStatus,
        bool $hasGateway,
        bool $hasGatewayResponse,
        bool $hasProcessedAt
    ): array {
        $itemsQuery = (clone $baseQuery)
            ->when($hasStatus || ($hasGateway && $hasProcessedAt), function ($query) use ($hasStatus, $hasGateway, $hasProcessedAt) {
                $query->where(function ($query) use ($hasStatus, $hasGateway, $hasProcessedAt) {
                    if ($hasStatus) {
                        $query->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Failed->value]);
                    }

                    if ($hasGateway && $hasProcessedAt) {
                        $hasStatus
                            ? $query->orWhere(fn($query) => $query->whereNotNull('gateway')->whereNull('processed_at'))
                            : $query->whereNotNull('gateway')->whereNull('processed_at');
                    }
                });
            }, fn($query) => $query->whereRaw('1 = 0'));

        return $itemsQuery
            ->latest('created_at')
            ->limit(8)
            ->get()
            ->map(fn(Payment $payment) => $this->paymentIssuePayload($payment, $hasStatus, $hasGateway, $hasGatewayResponse))
            ->values()
            ->all();
    }

    private function paymentIssuePayload(
        Payment $payment,
        bool $hasStatus,
        bool $hasGateway,
        bool $hasGatewayResponse
    ): array {
        return [
            'id' => $payment->id,
            'order_id' => $payment->order_id,
            'order_reference_no' => $payment->order_reference_no,
            'order_status' => $payment->order?->status?->value ?? $payment->order?->status,
            'order_payment_status' => $payment->order?->payment_status?->value ?? $payment->order?->payment_status,
            'branch_id' => $payment->branch_id,
            'method' => $payment->method?->value ?? $payment->method,
            'amount' => $payment->amount?->round()->amount(),
            'status' => $hasStatus ? ($payment->status ?? null) : null,
            'gateway' => $hasGateway ? ($payment->gateway ?? null) : null,
            'gateway_transaction_id' => $payment->gateway_transaction_id ?? null,
            'gateway_error' => $hasGatewayResponse
                ? data_get($payment->gateway_response, 'error.description')
                    ?? data_get($payment->gateway_response, 'message')
                    ?? data_get($payment->gateway_response, 'error')
                : null,
            'processed_at' => $payment->processed_at?->toISOString(),
            'created_at' => $payment->created_at?->toISOString(),
            'cashier_name' => $payment->cashier?->name,
        ];
    }
}
