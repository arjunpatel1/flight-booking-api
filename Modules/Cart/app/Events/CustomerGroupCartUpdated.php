<?php

namespace Modules\Cart\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Cart\Models\CustomerGroupCart;

class CustomerGroupCartUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public readonly CustomerGroupCart $group,
        public readonly string $eventName,
        public readonly ?int $actorCustomerId = null,
        public readonly array $metadata = [],
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("customer-group.tenant.{$this->group->tenant_id}.group.{$this->group->id}")];
    }

    public function broadcastAs(): string
    {
        return 'customer.group.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'group_id' => $this->group->id,
            'event' => $this->eventName,
            'status' => $this->group->status,
            'version' => (int) $this->group->version,
        ];
    }
}
