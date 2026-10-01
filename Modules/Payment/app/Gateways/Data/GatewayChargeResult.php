<?php

namespace Modules\Payment\Gateways\Data;

use Modules\Payment\Gateways\GatewayPaymentStatus;

/**
 * Normalised result of a terminal transaction, regardless of provider.
 */
readonly class GatewayChargeResult
{
    public function __construct(
        public GatewayPaymentStatus $status,
        public ?string $gatewayTransactionId = null,
        public ?string $approvalCode = null,
        public ?string $cardLast4 = null,
        public ?string $cardScheme = null,
        public ?string $message = null,
        public array $raw = [],
    ) {
    }

    public function isApproved(): bool
    {
        return $this->status === GatewayPaymentStatus::Approved;
    }

    public function isPending(): bool
    {
        return $this->status === GatewayPaymentStatus::Pending;
    }

    public function isDeclined(): bool
    {
        return $this->status === GatewayPaymentStatus::Declined;
    }

    public static function pending(?string $gatewayTransactionId, array $raw = []): self
    {
        return new self(
            status: GatewayPaymentStatus::Pending,
            gatewayTransactionId: $gatewayTransactionId,
            raw: $raw,
        );
    }

    public static function failed(string $message, array $raw = []): self
    {
        return new self(
            status: GatewayPaymentStatus::Failed,
            message: $message,
            raw: $raw,
        );
    }
}
