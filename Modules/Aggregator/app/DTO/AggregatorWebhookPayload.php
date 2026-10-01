<?php

namespace Modules\Aggregator\DTO;

class AggregatorWebhookPayload
{
    public static function normalize(array $payload): array
    {
        return [
            'version' => 'internal.webhook.v1',
            'event_type' => $payload['event_type'] ?? $payload['type'] ?? null,
            'external_event_id' => $payload['event_id'] ?? $payload['id'] ?? null,
            'external_order_id' => $payload['order_id']
                ?? $payload['external_order_id']
                ?? data_get($payload, 'data.external_order_id')
                ?? data_get($payload, 'payload.external_order_id'),
            'raw' => $payload,
        ];
    }
}
