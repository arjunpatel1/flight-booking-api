<?php

namespace Modules\Aggregator\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Aggregator\Models\AggregatorWebhookEvent;

/** @mixin AggregatorWebhookEvent */
class AggregatorWebhookEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'integration' => [
                'id' => $this->aggregator_integration_id,
                'name' => $this->relationLoaded('integration') ? $this->integration?->name : '',
            ],
            'event_type' => $this->event_type,
            'external_event_id' => $this->external_event_id,
            'status' => $this->status?->toTrans(),
            'signature' => $this->signature,
            'source_ip' => $this->source_ip,
            'headers' => $this->headers,
            'payload' => $this->payload,
            'error_message' => $this->error_message,
            'processed_at' => dateTimeFormat($this->processed_at),
            'created_at' => dateTimeFormat($this->created_at),
        ];
    }
}
