<?php

namespace Modules\ActivityLog\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use Modules\ActivityLog\Models\ActivityLog;
use Modules\User\Transformers\Api\V1\UserSimpleResource;

/** @mixin ActivityLog */
class ActivityLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $agent = $this->agent;

        $actor = $this->causer
            ? (new UserSimpleResource($this->causer))->resolve($request)
            : $this->systemActor();

        return [
            'id' => $this->id,
            'ip' => $this->properties['info']['ip'] ?? '-',
            'log_name' => $this->log_name,
            'description' => __($this->description, array_map(fn ($param) => __($param), $this->properties['trans_params'] ?? [])),
            'subject' => $this->getSubjectText(),
            'agent' => [
                'desktop' => $agent['is_desktop'],
                'platform' => $agent['platform'] ?: null,
                'browser' => $agent['browser'] ?: null,
            ],
            'user' => $actor,
            'event' => $this->translatedEvent(),
            'event_key' => $this->event,
            'created_at' => dateTimeFormat($this->created_at),
        ];
    }

    private function systemActor(): array
    {
        $source = Str::before((string) $this->log_name, '.');
        $name = match (true) {
            str_contains($source, 'print') => 'Print service',
            str_contains($source, 'delivery') => 'Delivery service',
            str_contains($source, 'whatsapp') => 'WhatsApp service',
            str_contains($source, 'payment') => 'Payment service',
            default => 'System automation',
        };

        return [
            'id' => null,
            'name' => $name,
            'username' => null,
            'email' => 'Automated background operation',
            'role' => ['id' => null, 'key' => 'system', 'name' => 'system', 'display_name' => 'System'],
            'profile_photo_url' => null,
            'is_system' => true,
        ];
    }

    /**
     * Human-readable event name.
     *
     * Events are written by any module (feature_flag_updated,
     * tenant_handoff_issued, …) but only a few have translations. A missing
     * key makes __() return the key itself, which is how raw
     * "activitylog::activity_logs.events.*" strings reached the Event column.
     * Fall back to a humanised form so a new event type can never leak a key.
     */
    private function translatedEvent(): ?string
    {
        if (blank($this->event)) {
            return null;
        }

        $key = "activitylog::activity_logs.events.$this->event";
        $translated = __($key);

        return $translated === $key
            ? Str::headline($this->event)
            : $translated;
    }
}
