<?php

namespace Modules\Order\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Events\PlatformEvent;
use Modules\Order\Events\Concerns\BroadcastsOrderRealtime;
use Modules\Order\Models\Order;

class OrderUpdated implements ShouldBroadcast
{
    use BroadcastsOrderRealtime, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param Order $order
     * @param Order|null $oldOrder
     */
    public function __construct(public Order $order, public ?Order $oldOrder = null)
    {
    }

    public function broadcastAs(): string
    {
        return PlatformEvent::ORDER_UPDATED;
    }
}
