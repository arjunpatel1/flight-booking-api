<?php

namespace Modules\Aggregator\Jobs;

class AggregatorOrderSyncJob extends SyncAggregatorIntegrationJob
{
    public function __construct(int $integrationId, array $payload = [])
    {
        parent::__construct($integrationId, 'order', $payload);
    }
}
