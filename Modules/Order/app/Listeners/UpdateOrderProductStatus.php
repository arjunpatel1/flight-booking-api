<?php

namespace Modules\Order\Listeners;

use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderUpdateStatus;

class UpdateOrderProductStatus
{
    /**
     * Handle the event.
     */
    public function handle(OrderUpdateStatus $event): void
    {
        if ($event->stopUpdateOrderProductStatus) {
            return;
        }

        $query = $event->order->products()
            ->whereNotIn("status", [OrderProductStatus::Cancelled, OrderProductStatus::Refunded]);

        switch ($event->status->value) {
            case OrderStatus::Preparing->value:
                $query
                    ->where('status', OrderProductStatus::Pending)
                    ->update(["status" => $event->status]);
                break;
            case OrderStatus::Ready->value:
                $query
                    ->where('status', OrderProductStatus::Preparing)
                    ->update(["status" => $event->status]);
                break;
            case OrderStatus::Served->value:
                $query
                    ->where('status', OrderProductStatus::Ready)
                    ->update(["status" => $event->status]);
                break;
            case OrderStatus::Completed->value:
                $query
                    ->whereNotIn("status", [OrderProductStatus::Cancelled, OrderProductStatus::Refunded])
                    ->update(["status" => OrderProductStatus::Served]);
                break;
            case OrderStatus::Cancelled->value:
            case OrderStatus::Refunded->value:
                $event->order->products()
                    ->whereNotIn("status", [OrderProductStatus::Cancelled, OrderProductStatus::Refunded])
                    ->update(["status" => $event->status->value]);
                break;
        }
    }
}
