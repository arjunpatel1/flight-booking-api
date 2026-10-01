<?php

namespace Modules\Aggregator\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Aggregator\Models\AggregatorSyncLog;

/** @mixin AggregatorSyncLog */
class AggregatorSyncLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'integration' => [
                'id' => $this->aggregator_integration_id,
                'name' => $this->relationLoaded('integration') ? $this->integration?->name : '',
            ],
            'type' => $this->type?->toTrans(),
            'status' => $this->status?->toTrans(),
            'reference' => $this->reference,
            'message' => $this->message,
            'error_message' => $this->error_message,
            'request_payload' => $this->request_payload,
            'response_payload' => $this->response_payload,
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'next_retry_at' => dateTimeFormat($this->next_retry_at),
            'started_at' => dateTimeFormat($this->started_at),
            'finished_at' => dateTimeFormat($this->finished_at),
            'created_at' => dateTimeFormat($this->created_at),
        ];
    }
}
