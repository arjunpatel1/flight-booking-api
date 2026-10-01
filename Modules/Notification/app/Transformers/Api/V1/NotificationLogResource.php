<?php

namespace Modules\Notification\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Notification\Models\NotificationLog;

/** @mixin NotificationLog */
class NotificationLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type ?: 'system',
            'channel' => $this->channel?->value ?? $this->channel,
            'recipient' => $this->recipient,
            'status' => $this->status?->value ?? $this->status,
            'error_message' => $this->error_message,
            'queued_at' => dateTimeFormat($this->queued_at),
            'sent_at' => dateTimeFormat($this->sent_at),
            'failed_at' => dateTimeFormat($this->failed_at),
            'created_at' => dateTimeFormat($this->created_at),
        ];
    }
}
