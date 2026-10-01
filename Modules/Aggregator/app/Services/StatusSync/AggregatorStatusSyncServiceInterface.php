<?php

namespace Modules\Aggregator\Services\StatusSync;

use Modules\Aggregator\Models\AggregatorSyncLog;

interface AggregatorStatusSyncServiceInterface
{
    public function sync(int $integrationId, array $payload = []): AggregatorSyncLog;
}
