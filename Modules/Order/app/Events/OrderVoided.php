<?php

namespace Modules\Order\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Events\PlatformEvent;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\Concerns\BroadcastsOrderRealtime;
use Modules\Order\Models\Order;
use Modules\Payment\Enums\RefundPaymentMethod;
use Modules\Pos\Models\PosSession;

class OrderVoided implements ShouldBroadcast
{
    use BroadcastsOrderRealtime, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param Order $order
     * @param OrderStatus $status
     * @param RefundPaymentMethod|null $refundPaymentMethod
     * @param PosSession|null $posSession
     * @param string|null $note
     */
    public function __construct(
        public Order                $order,
        public OrderStatus          $status,
        public ?RefundPaymentMethod $refundPaymentMethod = null,
        public ?PosSession          $posSession = null,
        public ?string              $note = null,
    )
    {
    }

    public function broadcastAs(): string
    {
        return PlatformEvent::ORDER_CANCELLED;
    }
}
