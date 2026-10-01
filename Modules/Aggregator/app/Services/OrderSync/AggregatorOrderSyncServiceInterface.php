<?php

namespace Modules\Aggregator\Services\OrderSync;

use Modules\Aggregator\Models\AggregatorSyncLog;

interface AggregatorOrderSyncServiceInterface
{
    public function sync(int $integrationId, array $payload = []): AggregatorSyncLog;
}
