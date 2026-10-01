<?php

namespace Modules\Order\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Events\PlatformEvent;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\Concerns\BroadcastsOrderRealtime;
use Modules\Order\Models\Order;

class OrderUpdateStatus implements ShouldBroadcast
{
    use BroadcastsOrderRealtime, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param Order $order
     * @param OrderStatus $status
     * @param int|null $reasonId
     * @param int|null $changedById
     * @param string|null $note
     * @param bool $stopUpdateOrderProductStatus
     */
    public function __construct(
        public Order       $order,
        public OrderStatus $status,
        public ?int        $reasonId = null,
        public ?int        $changedById = null,
        public ?string     $note = null,
        public bool        $stopUpdateOrderProductStatus = false,
    )
    {
    }

    public function broadcastAs(): string
    {
        return $this->status === OrderStatus::Cancelled
            ? PlatformEvent::ORDER_CANCELLED
            : PlatformEvent::ORDER_UPDATED;
    }
}
