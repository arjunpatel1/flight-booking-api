<?php

namespace Modules\Pos\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Events\PlatformEvent;

/**
 * RAE v2 live automation stream — one broadcast event reused for every
 * automation lifecycle transition (executed/completed/failed/rolled_back),
 * carrying the canonical PlatformEvent envelope on the existing branch channel
 * so it flows through the unified event bus (no parallel realtime).
 */
class AutomationStreamEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public int $tries = 5;

    public int $backoff = 5;

    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        public string $eventName,
        public int|string|null $entityId,
        public ?int $branchId,
        public array $payload = [],
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('branch.'.($this->branchId ?? 'all'))];
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    /**
     * @return array<string,mixed>
     */
    public function broadcastWith(): array
    {
        return PlatformEvent::envelope(
            eventName: $this->eventName,
            entity: 'automation_execution',
            entityId: $this->entityId,
            branchId: $this->branchId,
            payload: $this->payload,
        );
    }
}
