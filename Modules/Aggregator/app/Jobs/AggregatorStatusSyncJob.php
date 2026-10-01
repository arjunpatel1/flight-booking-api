<?php

namespace Modules\Aggregator\Jobs;

class AggregatorStatusSyncJob extends SyncAggregatorIntegrationJob
{
    public function __construct(int $integrationId, array $payload = [])
    {
        parent::__construct($integrationId, 'status', $payload);
    }
}
