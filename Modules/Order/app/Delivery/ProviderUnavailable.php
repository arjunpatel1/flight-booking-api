<?php

namespace Modules\Order\Delivery;

use RuntimeException;

final class ProviderUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct(match ($reasonCode) {
            'PROVIDER_NOT_CONFIGURED' => 'Delivery provider credentials are missing.',
            'PROVIDER_INTEGRATION_DISABLED' => 'Platform delivery integration is disabled.',
            'PROVIDER_PRODUCTION_DISABLED' => 'Production delivery provider access is disabled.',
            'PROVIDER_SANDBOX_DISABLED' => 'Sandbox delivery provider access is disabled.',
            'PROVIDER_BOOKING_DISABLED' => 'Delivery provider booking is disabled.',
            'PROVIDER_CANCELLATION_DISABLED' => 'Delivery provider cancellation is disabled.',
            'PROVIDER_WEBHOOK_DISABLED' => 'Delivery provider callback ingestion is disabled.',
            'PROVIDER_STORE_NOT_CONFIGURED' => 'The delivery network outlet identifier is missing.',
            'BOOKING_IDEMPOTENCY_UNDOCUMENTED' => 'Automatic delivery booking is unavailable because safe duplicate prevention is not configured.',
            'PROVIDER_AUTHENTICATION_ERROR' => 'The delivery provider rejected its configured credentials.',
            'PROVIDER_INVALID_RESPONSE' => 'The delivery provider returned an invalid response. Review this order manually.',
            'PROVIDER_REQUEST_FAILED' => 'The delivery provider request failed. Review this order manually.',
            'PROVIDER_TIMEOUT', 'UNKNOWN_PROVIDER_RESULT' => 'The provider result is unknown. Do not retry booking; investigate manually.',
            'PROVIDER_NETWORK_ERROR' => 'The delivery provider network request failed.',
            'PROVIDER_4XX' => 'The delivery provider rejected the request.',
            'PROVIDER_5XX' => 'The delivery provider is temporarily unavailable.',
            'WEBHOOK_AUTH_CONTRACT_UNAVAILABLE' => 'Provider callbacks are disabled until an official authentication contract is supplied.',
            'WEBHOOK_AUTHENTICATION_FAILED' => 'The delivery callback could not be authenticated.',
            default => 'The provider API contract is not available. Assign this delivery manually.',
        });
    }
}
