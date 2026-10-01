<?php

namespace Modules\Order\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Events\PlatformEvent;
use Modules\Order\Events\Concerns\BroadcastsOrderRealtime;
use Modules\Order\Models\Order;

class OrderCreated implements ShouldBroadcast
{
    use BroadcastsOrderRealtime, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param Order $order
     */
    public function __construct(
        public Order $order,
        public bool $shouldPrintKitchenTicket = true,
    )
    {
    }

    public function broadcastAs(): string
    {
        return PlatformEvent::ORDER_CREATED;
    }
}
