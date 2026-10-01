<?php

namespace Modules\Voice\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Events\PlatformEvent;
use Modules\Voice\Models\VoiceSetting;

class VoiceAnnouncementTriggered implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $tries = 10;

    public int $backoff = 5;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public int $announcementId,
        public int $branchId,
        public ?int $orderId,
        public string $announcementText,
        public string $eventType,
        public VoiceSetting $settings,
        public ?int $actorUserId = null,
        public ?string $actorDeviceId = null,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->branchId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return PlatformEvent::VOICE_ANNOUNCEMENT_CREATED;
    }

    /**
     * Limit broadcast payload to the fields clients need.
     */
    public function broadcastWith(): array
    {
        $data = [
            'announcement_id' => $this->announcementId,
            'branch_id' => $this->branchId,
            'order_id' => $this->orderId,
            'announcement_text' => $this->announcementText,
            'event_type' => $this->eventType,
            // Origin of the action. Clients use this to skip speaking an
            // announcement caused by the listening user/device itself, while
            // still applying the event to local state. Null for system-
            // generated announcements (scheduled jobs), which everyone hears.
            'actor_user_id' => $this->actorUserId,
            'actor_device_id' => $this->actorDeviceId,
            'voice' => [
                'gender' => $this->settings->voice_gender,
                'rate' => $this->settings->voice_rate,
                'volume' => $this->settings->voice_volume,
                'device_id' => $this->settings->selected_device_id,
                'device_name' => $this->settings->selected_device_name,
            ],
            // Flat compatibility fields are consumed by older Linux agents.
            'voice_gender' => $this->settings->voice_gender,
            'rate' => $this->settings->voice_rate,
            'volume' => $this->settings->voice_volume,
            'device_id' => $this->settings->selected_device_id,
            'device_name' => $this->settings->selected_device_name,
        ];

        return [
            ...PlatformEvent::envelope(
                eventName: PlatformEvent::VOICE_ANNOUNCEMENT_CREATED,
                entity: 'voice_announcement',
                entityId: $this->announcementId,
                branchId: $this->branchId,
                payload: $data,
            ),
            // Legacy keys retained for the Print Agent voice consumer.
            ...$data,
        ];
    }
}
