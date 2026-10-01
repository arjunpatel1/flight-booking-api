<?php

namespace Modules\Notification\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Events\PlatformEvent;
use Modules\Notification\Models\Notification;

class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public int $tries = 10;

    public int $backoff = 5;

    public function __construct(public Notification $notification) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("notifications.user.{$this->notification->target_user_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return PlatformEvent::NOTIFICATION_CREATED;
    }

    public function broadcastWith(): array
    {
        $data = [
            'id' => $this->notification->id,
            'title' => $this->notification->title,
            'message' => $this->notification->message,
            'type' => $this->notification->type,
            'severity' => $this->notification->severity->value,
            'icon' => $this->notification->icon ?: $this->notification->severity->icon(),
            'color' => $this->notification->color ?: $this->notification->severity->color(),
            'action_url' => $this->notification->action_url,
            'payload' => $this->notification->payload,
            'is_read' => false,
            'created_at' => dateTimeFormat($this->notification->created_at),
            'created_at_iso' => $this->notification->created_at?->toIso8601String(),
        ];

        return [
            // Envelope first; legacy keys (incl. domain `payload`) spread last so
            // they always win any key collision — zero regression for consumers.
            ...PlatformEvent::envelope(
                eventName: PlatformEvent::NOTIFICATION_CREATED,
                entity: 'notification',
                entityId: $this->notification->id,
                branchId: $this->notification->branch_id ?? null,
                payload: $data,
            ),
            ...$data,
        ];
    }
}
