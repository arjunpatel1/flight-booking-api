<?php

namespace Modules\Order\Delivery;

final class UengageDeliveryProvider implements DeliveryProvider
{
    public function __construct(
        private readonly UengageClient $client,
        private readonly UengageTaskPayload $payload,
    ) {}

    public function code(): string
    {
        return 'uengage';
    }

    public function isConfigured(): bool
    {
        return filled(app(PlatformDeliveryCredentials::class)->apiKey()) && filled(app(PlatformDeliveryCredentials::class)->storeId());
    }

    public function quotes(DeliveryLocation $pickup, DeliveryLocation $dropoff, string $orderReference, bool $cod): array
    {
        $this->assertAvailable();
        $body = $this->client->serviceability(
            (string) app(PlatformDeliveryCredentials::class)->apiKey(),
            (string) app(PlatformDeliveryCredentials::class)->storeId(),
            $pickup,
            $dropoff,
        );
        $riderServiceable = data_get($body, 'serviceability.riderServiceAble');
        $locationServiceable = data_get($body, 'serviceability.locationServiceAble');
        if (! is_bool($riderServiceable) || ! is_bool($locationServiceable)) {
            throw new ProviderUnavailable('PROVIDER_INVALID_RESPONSE');
        }
        $serviceable = $riderServiceable && $locationServiceable;
        $status = (string) data_get($body, 'status');
        $cost = data_get($body, 'payouts.total');
        $unserviceableReason = trim((string) data_get($body, 'payouts.message'));
        // Flash returns HTTP 200 with application status "400" for a valid,
        // definitive unavailable route. Authentication failures have already
        // been rejected by UengageClient. Treat only the documented all-false
        // serviceability shape as unavailable; malformed/mixed shapes fail closed.
        $definitivelyUnavailable = ! $riderServiceable && ! $locationServiceable
            && $status === '400' && is_string(data_get($body, 'payouts.message'))
            && trim((string) data_get($body, 'payouts.message')) !== '';
        if ((! $serviceable && ! $definitivelyUnavailable)
            || ($serviceable && ($status !== '200' || ! is_numeric($cost)
                || ! is_finite((float) $cost) || (float) $cost < 0))) {
            throw new ProviderUnavailable('PROVIDER_INVALID_RESPONSE');
        }

        return [new DeliveryQuote(
            partnerCode: 'uengage_auto',
            partnerName: 'uEngage auto allocation',
            cost: $serviceable ? (float) $cost : 0,
            etaMinutes: null,
            reference: null,
            serviceable: $serviceable,
            unserviceableReason: $serviceable ? null : mb_substr($unserviceableReason, 0, 240),
        )];
    }

    public function book(DeliveryQuote $quote, string $orderReference, string $idempotencyKey): DeliveryBookingResult
    {
        $this->assertAvailable();
        $credentials = app(PlatformDeliveryCredentials::class);
        $body = $this->client->createTask(
            (string) $credentials->apiKey(),
            $this->payload->forOrder($orderReference, (string) $credentials->storeId()),
        );

        if (($body['status'] ?? false) !== true) {
            return new DeliveryBookingResult(
                false,
                failureCode: is_string($body['Status_code'] ?? null) ? $body['Status_code'] : 'PROVIDER_REJECTED',
                failureReason: is_string($body['message'] ?? null) ? $body['message'] : null,
            );
        }

        return new DeliveryBookingResult(true, (string) $body['taskId']);
    }

    private function assertAvailable(): void
    {
        if (! config('delivery.integration_enabled', false)) {
            throw new ProviderUnavailable('PROVIDER_INTEGRATION_DISABLED');
        }
        if (! $this->isConfigured()) {
            throw new ProviderUnavailable(filled(app(PlatformDeliveryCredentials::class)->apiKey())
                ? 'PROVIDER_STORE_NOT_CONFIGURED' : 'PROVIDER_NOT_CONFIGURED');
        }
    }
}
