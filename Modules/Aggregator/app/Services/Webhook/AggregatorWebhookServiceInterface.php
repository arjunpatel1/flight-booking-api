<?php

namespace Modules\Aggregator\Services\Webhook;

use Modules\Aggregator\Models\AggregatorWebhookEvent;

interface AggregatorWebhookServiceInterface
{
    public function store(int $integrationId, array $headers, array $payload): AggregatorWebhookEvent;

    public function process(int $eventId): void;
}
