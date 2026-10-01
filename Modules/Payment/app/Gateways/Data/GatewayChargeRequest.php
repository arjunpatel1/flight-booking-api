<?php

namespace Modules\Payment\Gateways\Data;

/**
 * A request to charge a card on a physical payment terminal.
 *
 * @property-read float $amount Amount in major currency units (e.g. rupees).
 */
readonly class GatewayChargeRequest
{
    public function __construct(
        public float $amount,
        public string $currency,
        public string $orderReferenceNo,
        public string $reference,        // our unique txn reference (idempotency)
        public string $method = 'card',
        public ?string $terminalId = null, // store/terminal selection for the driver
        public array $metadata = [],
    ) {
    }

    /** Amount in minor units (paise/cents) — what most terminal APIs expect. */
    public function amountInMinorUnits(): int
    {
        return (int) round($this->amount * 100);
    }
}
