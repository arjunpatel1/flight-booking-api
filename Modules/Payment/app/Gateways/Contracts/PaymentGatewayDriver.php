<?php

namespace Modules\Payment\Gateways\Contracts;

use Modules\Payment\Gateways\Data\GatewayChargeRequest;
use Modules\Payment\Gateways\Data\GatewayChargeResult;

/**
 * A card-present payment terminal driver. Implementations talk to a specific
 * provider (e.g. Pine Labs Plutus Smart) or to a manually-operated terminal.
 */
interface PaymentGatewayDriver
{
    /** Stable key used in config + the `gateway` request field (e.g. "pinelabs"). */
    public function key(): string;

    /** True when the driver has the credentials/config it needs to operate. */
    public function isConfigured(): bool;

    /**
     * Charge the card on the terminal. For real terminals this initiates the
     * transaction and waits (bounded) for the cardholder to complete it,
     * returning the final approved/declined result — or Pending on timeout.
     */
    public function charge(GatewayChargeRequest $request): GatewayChargeResult;

    /** Poll the current status of a previously-initiated transaction. */
    public function status(string $gatewayTransactionId, array $context = []): GatewayChargeResult;

    /** Reverse/void an approved transaction (e.g. on a downstream failure). */
    public function reverse(string $gatewayTransactionId, GatewayChargeRequest $original): GatewayChargeResult;
}
