<?php

namespace Modules\Payment\Gateways\Drivers;

use Modules\Payment\Gateways\Contracts\PaymentGatewayDriver;
use Modules\Payment\Gateways\Data\GatewayChargeRequest;
use Modules\Payment\Gateways\Data\GatewayChargeResult;
use Modules\Payment\Gateways\GatewayPaymentStatus;

/**
 * Records a card payment captured on a standalone (non-integrated) terminal.
 * The cashier runs the card on the PIN pad and enters the approval/reference,
 * which we store for reconciliation. Always available — no vendor onboarding.
 */
class ManualTerminalDriver implements PaymentGatewayDriver
{
    public function key(): string
    {
        return 'manual';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function charge(GatewayChargeRequest $request): GatewayChargeResult
    {
        $reference = $request->metadata['approval_reference']
            ?? $request->metadata['transaction_id']
            ?? $request->reference;

        return new GatewayChargeResult(
            status: GatewayPaymentStatus::Approved,
            gatewayTransactionId: (string) $reference,
            approvalCode: $request->metadata['approval_code'] ?? null,
            cardLast4: $request->metadata['card_last4'] ?? null,
            cardScheme: $request->metadata['card_scheme'] ?? null,
            message: 'Captured on external terminal',
            raw: ['driver' => 'manual', 'reference' => $reference],
        );
    }

    public function status(string $gatewayTransactionId, array $context = []): GatewayChargeResult
    {
        return new GatewayChargeResult(
            status: GatewayPaymentStatus::Approved,
            gatewayTransactionId: $gatewayTransactionId,
        );
    }

    public function reverse(string $gatewayTransactionId, GatewayChargeRequest $original): GatewayChargeResult
    {
        // Reversal on a standalone terminal is performed physically; we just
        // acknowledge so the caller can record the void.
        return new GatewayChargeResult(
            status: GatewayPaymentStatus::Approved,
            gatewayTransactionId: $gatewayTransactionId,
            message: 'Reverse on the terminal manually',
        );
    }
}
