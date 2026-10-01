<?php

namespace Modules\Notification\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Notification\Models\Notification;

/** @mixin Notification */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "title" => $this->title,
            "message" => $this->message,
            "type" => $this->type,
            "severity" => $this->severity->value,
            "icon" => $this->icon ?: $this->severity->icon(),
            "color" => $this->color ?: $this->severity->color(),
            "action_url" => $this->action_url,
            "payload" => $this->payload,
            "is_read" => !is_null($this->read_at),
            "read_at" => $this->read_at ? dateTimeFormat($this->read_at) : null,
            "created_at" => dateTimeFormat($this->created_at),
            "created_at_iso" => $this->created_at?->toIso8601String(),
        ];
    }
}
