<?php

namespace Modules\Order\Listeners;

use Modules\Order\Events\OrderUpdateStatus;

class StoreOrderStatusLogo
{
    /**
     * Handle the event.
     */
    public function handle(OrderUpdateStatus $event): void
    {
        $event->order->storeStatusLog(
            status: $event->status,
            changedById: $event->changedById,
            reasonId: $event->reasonId,
            note: $event->note
        );
    }
}
