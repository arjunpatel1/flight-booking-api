<?php

namespace Modules\Order\Listeners;

use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Models\Order;

class UpdateOrderStatus
{
    /**
     * Handle the event.
     */
    public function handle(OrderCreated $event): void
    {
        if ($event->order->status === OrderStatus::Pending) {
            $event->order->update(['status' => OrderStatus::Confirmed]);
            $this->storeStatusLogs($event->order);
        }
    }

    /**
     * Store status logs
     *
     * @param Order $order
     * @return void
     */
    private function storeStatusLogs(Order $order): void
    {
        foreach ([OrderStatus::Pending, OrderStatus::Confirmed] as $status) {
            $order->storeStatusLog(status: $status);
        }
    }
}
