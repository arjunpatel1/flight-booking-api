<?php

namespace Modules\Aggregator\Services\Providers;

use Modules\Aggregator\Models\AggregatorIntegration;

interface AggregatorProviderInterface
{
    public function menuSync(AggregatorIntegration $integration, array $payload = []): array;

    public function orderSync(AggregatorIntegration $integration, array $payload = []): array;

    public function statusSync(AggregatorIntegration $integration, array $payload = []): array;

    /**
     * Push item availability ("86") changes so out-of-stock items are hidden on
     * the aggregator storefront.
     */
    public function itemAvailabilitySync(AggregatorIntegration $integration, array $payload = []): array;

    public function verifyWebhook(AggregatorIntegration $integration, array $headers, array $payload): bool;

    public function capabilities(): array;
}
