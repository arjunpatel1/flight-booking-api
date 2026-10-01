<?php

namespace Modules\Aggregator\Services\ItemAvailability;

use Modules\Aggregator\Models\AggregatorSyncLog;

interface AggregatorItemAvailabilityServiceInterface
{
    /**
     * Push item availability ("86") changes to a single integration.
     *
     * @param array $payload expects items => [['product_id' => int, 'available' => bool], ...]
     */
    public function sync(int $integrationId, array $payload = []): AggregatorSyncLog;
}
