<?php

namespace Modules\WhatsAppCenter\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsAppMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'direction' => $this->direction,
            'type' => $this->type,
            'body' => $this->body,
            'status' => $this->status,
            'provider_message_id' => $this->provider_message_id,
            'retry_count' => max(0, (int) data_get($this->payload, 'attempts', 0) - 1),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
