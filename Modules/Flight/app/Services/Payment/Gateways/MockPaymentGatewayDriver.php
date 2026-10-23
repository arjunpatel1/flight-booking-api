<?php

namespace Modules\Flight\Services\Payment\Gateways;

use Modules\Payment\Gateways\Contracts\PaymentGatewayDriver;
use Modules\Payment\Gateways\Data\GatewayChargeRequest;
use Modules\Payment\Gateways\Data\GatewayChargeResult;
use Modules\Payment\Gateways\GatewayPaymentStatus;

class MockPaymentGatewayDriver implements PaymentGatewayDriver
{
    private string $simulatedMode = 'SUCCESS';

    public function __construct(array $config = [])
    {
        $this->simulatedMode = strtoupper($config['mode'] ?? 'SUCCESS');
    }

    public function setSimulatedMode(string $mode): self
    {
        $this->simulatedMode = strtoupper($mode);
        return $this;
    }

    public function key(): string
    {
        return 'mock';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function charge(GatewayChargeRequest $request): GatewayChargeResult
    {
        $requestedMode = strtoupper($request->metadata['simulated_status'] ?? $this->simulatedMode);

        if ($requestedMode === 'FAILURE' || $requestedMode === 'FAILED') {
            return GatewayChargeResult::failed(
                $request->metadata['error_message'] ?? 'Mock payment was declined by issuer.'
            );
        }

        if ($requestedMode === 'PENDING') {
            return GatewayChargeResult::pending(
                'mock_txn_' . bin2hex(random_bytes(8)),
                ['message' => 'Mock payment is awaiting customer authorization.']
            );
        }

        $mockTxnId = $request->metadata['razorpay_payment_id'] ?? ('mock_pay_' . bin2hex(random_bytes(8)));

        return new GatewayChargeResult(
            status: GatewayPaymentStatus::Approved,
            gatewayTransactionId: $mockTxnId,
            cardScheme: 'VISA',
            message: 'Mock payment approved successfully.',
            raw: [
                'id' => $mockTxnId,
                'status' => 'captured',
                'amount' => $request->amountInMinorUnits(),
                'currency' => $request->currency,
            ]
        );
    }

    public function status(string $gatewayTransactionId, array $context = []): GatewayChargeResult
    {
        $mode = strtoupper($context['simulated_status'] ?? $this->simulatedMode);

        return new GatewayChargeResult(
            status: match ($mode) {
                'FAILURE', 'FAILED' => GatewayPaymentStatus::Declined,
                'PENDING'           => GatewayPaymentStatus::Pending,
                default             => GatewayPaymentStatus::Approved,
            },
            gatewayTransactionId: $gatewayTransactionId,
            cardScheme: 'VISA',
            message: 'Mock status checked.',
            raw: ['id' => $gatewayTransactionId, 'status' => strtolower($mode)]
        );
    }

    public function reverse(string $gatewayTransactionId, GatewayChargeRequest $original): GatewayChargeResult
    {
        return new GatewayChargeResult(
            status: GatewayPaymentStatus::Approved,
            gatewayTransactionId: 'mock_rfnd_' . bin2hex(random_bytes(6)),
            cardScheme: null,
            message: 'Mock refund completed.',
            raw: ['status' => 'refunded']
        );
    }
}
