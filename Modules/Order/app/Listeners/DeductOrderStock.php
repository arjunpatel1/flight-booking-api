<?php

namespace Modules\Order\Listeners;

use Illuminate\Validation\ValidationException;
use Modules\Inventory\Services\StockSync\StockSyncServiceInterface;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Events\OrderUpdateStatus;
use Throwable;

class DeductOrderStock
{
    /**
     * Handle the event.
     */
    public function handle(OrderCreated|OrderUpdateStatus $event): void
    {
        $status = $event instanceof OrderUpdateStatus
            ? $event->status
            : $event->order->status;

        if (!$this->shouldDeduct($status)) {
            return;
        }

        try {
            app(StockSyncServiceInterface::class)->deductOrderStock($event->order);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw $exception;
        }
    }

    private function shouldDeduct(OrderStatus $status): bool
    {
        return in_array($status, [
            OrderStatus::Confirmed,
            OrderStatus::Preparing,
            OrderStatus::Ready,
            OrderStatus::Served,
            OrderStatus::Completed,
        ], true);
    }
}
