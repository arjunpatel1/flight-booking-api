<?php

namespace Modules\Aggregator\Services\MenuSync;

use Modules\Aggregator\Models\AggregatorSyncLog;

interface AggregatorMenuSyncServiceInterface
{
    public function sync(int $integrationId, array $payload = []): AggregatorSyncLog;
}
