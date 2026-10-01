<?php

namespace Modules\Payment\Services\Payment;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Order\Models\Order;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Enums\PaymentType;
use Modules\Payment\Gateways\Data\GatewayChargeRequest;
use Modules\Payment\Gateways\Data\GatewayChargeResult;
use Modules\Payment\Gateways\PaymentGatewayManager;
use Modules\Payment\Models\Payment;
use Modules\Support\GlobalStructureFilters;
use Throwable;

class PaymentService implements PaymentServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("payment::payments.payment");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->with(["branch:id,name", "cashier:id,name"])
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): Payment
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Payment::class;
    }

    /** @inheritDoc */
    public function show(int $id): Payment
    {
        return $this->getModel()
            ->query()
            ->with(["branch:id,name", "cashier:id,name"])
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function getStructureFilters(): array
    {
        $branchFilter = GlobalStructureFilters::branch();
        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            [
                "key" => 'method',
                "label" => __('payment::payments.filters.method'),
                "type" => 'select',
                "options" => PaymentMethod::toArrayTrans(),
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /**
     * Process payment for an order
     *
     * @param \Modules\Order\Models\Order $order
     * @param array $data
     * @return Payment
     */
    public function processPayment(Order $order, array $data): Payment
    {
        $gatewayKey = $data['gateway'] ?? null;
        $gatewayResult = null;

        if (!empty($gatewayKey)) {
            try {
                $gatewayResult = $this->chargeOnTerminal($order, $gatewayKey, $data);
            } catch (ValidationException $exception) {
                $this->recordGatewayAttempt(
                    order: $order,
                    data: $data,
                    status: PaymentStatus::Failed,
                    message: $this->firstValidationMessage($exception)
                );
                throw $exception;
            } catch (Throwable $exception) {
                report($exception);
                $this->recordGatewayAttempt(
                    order: $order,
                    data: $data,
                    status: PaymentStatus::Failed,
                    message: $exception->getMessage()
                );

                throw ValidationException::withMessages([
                    'gateway' => __('payment::payments.gateway_declined'),
                ]);
            }

            if (!$gatewayResult->isApproved()) {
                $status = $gatewayResult->isPending()
                    ? PaymentStatus::Pending
                    : PaymentStatus::Failed;

                $this->recordGatewayAttempt(
                    order: $order,
                    data: $data,
                    status: $status,
                    result: $gatewayResult,
                    message: $gatewayResult->message ?: __('payment::payments.gateway_declined')
                );

                throw ValidationException::withMessages([
                    'gateway' => $gatewayResult->message ?: __('payment::payments.gateway_declined'),
                ]);
            }
        }

        $meta = $data['meta'] ?? [];
        if ($gatewayResult) {
            $meta = array_merge(is_array($meta) ? $meta : [], array_filter([
                'approval_code' => $gatewayResult->approvalCode,
                'card_last4' => $gatewayResult->cardLast4,
                'card_scheme' => $gatewayResult->cardScheme,
            ]));
        }

        $payment = $this->getModel()->create([
            'order_reference_no' => $order->reference_no,
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'cashier_id' => auth()->id(),
            'received_by' => auth()->id(),
            'transaction_id' => $gatewayResult?->gatewayTransactionId
                ?? ($data['transaction_id'] ?? null),
            'method' => $data['method'],
            'amount' => $data['amount'],
            'type' => PaymentType::Payment->value,
            ...$this->paymentStatusPayload(PaymentStatus::Completed),
            'currency' => $order->currency,
            'currency_rate' => $order->currency_rate,
            'received_at' => now(),
            'gateway' => $gatewayKey,
            'gateway_transaction_id' => $gatewayResult?->gatewayTransactionId,
            'gateway_response' => $gatewayResult?->raw,
            'processed_at' => now(),
            'meta' => empty($meta) ? null : $meta,
        ]);

        $order->refreshDueAmount();

        return $payment->loadMissing(['branch:id,name', 'cashier:id,name']);
    }

    /**
     * Run the amount through a card-present terminal. Only returns on an
     * approved or non-approved charge result. Unconfigured gateways still raise
     * a validation error before the driver is called.
     */
    private function chargeOnTerminal(Order $order, string $gatewayKey, array $data): GatewayChargeResult
    {
        $manager = app(PaymentGatewayManager::class);

        try {
            $driver = $manager->driver($gatewayKey);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'gateway' => __('payment::payments.gateway_not_configured', ['gateway' => $gatewayKey]),
            ]);
        }

        $gatewayData = (array) ($data['gateway_data'] ?? []);

        $result = $driver->charge(new GatewayChargeRequest(
            amount: (float) $data['amount'],
            currency: $order->currency,
            orderReferenceNo: $order->reference_no,
            reference: ($data['transaction_id'] ?? null) ?: (string) Str::uuid(),
            method: (string) $data['method'],
            terminalId: $gatewayData['terminal_id'] ?? null,
            metadata: $gatewayData,
        ));

        return $result;
    }

    private function recordGatewayAttempt(
        Order $order,
        array $data,
        PaymentStatus $status,
        ?GatewayChargeResult $result = null,
        ?string $message = null,
    ): void {
        $gatewayKey = $data['gateway'] ?? null;
        if (empty($gatewayKey) || ! $this->paymentStatusColumnExists()) {
            return;
        }

        $gatewayResponse = $result?->raw ?? [];
        if ($message) {
            $gatewayResponse = [
                ...$gatewayResponse,
                'message' => $message,
            ];
        }

        $this->getModel()->create([
            'order_reference_no' => $order->reference_no,
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'cashier_id' => auth()->id(),
            'received_by' => auth()->id(),
            'transaction_id' => $result?->gatewayTransactionId
                ?? ($data['transaction_id'] ?? data_get($data, 'gateway_data.payment_id')),
            'method' => $data['method'],
            'amount' => $data['amount'],
            'type' => PaymentType::Payment->value,
            ...$this->paymentStatusPayload($status),
            'currency' => $order->currency,
            'currency_rate' => $order->currency_rate,
            'received_at' => now(),
            'gateway' => $gatewayKey,
            'gateway_transaction_id' => $result?->gatewayTransactionId,
            'gateway_response' => empty($gatewayResponse) ? null : $gatewayResponse,
            'processed_at' => now(),
            'meta' => $data['meta'] ?? null,
        ]);
    }

    private function firstValidationMessage(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            if (!empty($messages[0])) {
                return (string) $messages[0];
            }
        }

        return $exception->getMessage();
    }

    private function paymentStatusPayload(PaymentStatus $status): array
    {
        return $this->paymentStatusColumnExists()
            ? ['status' => $status->value]
            : [];
    }

    private function paymentStatusColumnExists(): bool
    {
        static $exists = null;

        return $exists ??= Schema::hasColumn('payments', 'status');
    }

    /**
     * Settle the remaining due of an order as a complimentary (no-charge / NC) bill.
     * Recorded as a payment with the dedicated `complimentary` method so cash/card
     * revenue sums stay clean, while the order itself is marked fully settled. The
     * reason and authorising user are captured for audit.
     */
    public function complimentaryPayment(Order $order, string $reason): Payment
    {
        $due = round($order->due_amount->amount(), 3);

        if ($due <= 0) {
            throw ValidationException::withMessages([
                'order_id' => __("payment::payments.nothing_due"),
            ]);
        }

        $payment = $this->getModel()->create([
            'order_reference_no' => $order->reference_no,
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'cashier_id' => auth()->id(),
            'received_by' => auth()->id(),
            'method' => PaymentMethod::Complimentary->value,
            'amount' => $due,
            'type' => PaymentType::Payment->value,
            ...$this->paymentStatusPayload(PaymentStatus::Completed),
            'currency' => $order->currency,
            'currency_rate' => $order->currency_rate,
            'received_at' => now(),
            'processed_at' => now(),
            'meta' => [
                'complimentary' => true,
                'reason' => $reason,
                'authorized_by' => auth()->id(),
            ],
        ]);

        $order->refreshDueAmount();

        return $payment->loadMissing(['branch:id,name', 'cashier:id,name']);
    }

    /**
     * Refund payment
     *
     * @param Payment $payment
     * @param string $reason
     * @return Payment
     */
    public function refundPayment(Payment $payment, string $reason): Payment
    {
        if ($payment->refunded_at) {
            return $payment->loadMissing(['branch:id,name', 'cashier:id,name']);
        }

        // Process refund through gateway if applicable
        if ($payment->gateway) {
            $this->refundWithGateway($payment);
        }

        // Update payment status
        $payment->update([
            ...$this->paymentStatusPayload(PaymentStatus::Refunded),
            'gateway_response' => $payment->gateway_response,
            'refund_reason' => $reason,
            'refunded_at' => now(),
        ]);

        return $payment->loadMissing(['branch:id,name', 'cashier:id,name']);
    }

    /**
     * Refund through gateway
     *
     * @param Payment $payment
     * @return void
     */
    private function refundWithGateway(Payment $payment): void
    {
        try {
            $driver = app(PaymentGatewayManager::class)->driver((string) $payment->gateway);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'gateway' => __("payment::payments.gateway_not_configured", ['gateway' => $payment->gateway]),
            ]);
        }

        $gatewayTransactionId = (string) ($payment->gateway_transaction_id ?: $payment->transaction_id);
        if ($gatewayTransactionId === '') {
            throw ValidationException::withMessages(['gateway' => 'The original gateway transaction reference is missing.']);
        }

        $result = $driver->reverse($gatewayTransactionId, new GatewayChargeRequest(
            amount: (float) $payment->amount->amount(),
            currency: $payment->currency,
            orderReferenceNo: $payment->order_reference_no,
            reference: 'refund-payment-'.$payment->id,
            method: $payment->method?->value ?? (string) $payment->method,
            metadata: array_merge((array) $payment->meta, ['payment_id' => $gatewayTransactionId]),
        ));

        if (! $result->isApproved()) {
            throw ValidationException::withMessages([
                'gateway' => $result->message ?: 'The payment gateway did not confirm the refund.',
            ]);
        }

        $payment->gateway_response = [
            'payment' => (array) $payment->gateway_response,
            'refund' => $result->raw,
        ];
    }
}
