<?php

namespace Modules\Order\Listeners;

use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\SeatingPlan\Enums\TableStatus;

class MarkTableAsFree
{
    /**
     * Handle the event.
     */
    public function handle(OrderUpdateStatus $event): void
    {
        if (
            in_array($event->status, [OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Refunded])
            && $event->order->type == OrderType::DineIn
            && !is_null($event->order->table_id)
        ) {
            $table = $event->order->table;
            $shouldMakeAvailable = $event->status === OrderStatus::Cancelled
                || ! (bool) setting('waiter_table_status_flow_enabled', true);
            $tableNewStatus = $shouldMakeAvailable ? TableStatus::Available : TableStatus::Cleaning;

            if ($table->status != $tableNewStatus) {
                $table->update(["status" => $tableNewStatus]);
                $table->storeStatusLog(
                    status: $tableNewStatus,
                    note: "ORDER_" . (strtoupper($event->status->value)) . " :{$event->order->reference_no}"
                );
            }
        }
    }
}
