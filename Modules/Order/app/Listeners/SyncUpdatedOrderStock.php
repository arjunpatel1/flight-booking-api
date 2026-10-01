<?php

namespace Modules\Order\Listeners;

use Illuminate\Validation\ValidationException;
use Modules\Inventory\Services\StockSync\StockSyncServiceInterface;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderUpdated;
use Throwable;

class SyncUpdatedOrderStock
{
    /**
     * Handle the event.
     */
    public function handle(OrderUpdated $event): void
    {
        if (!$this->shouldDeduct($event->order->status)) {
            return;
        }

        try {
            app(StockSyncServiceInterface::class)->resyncOrderStock($event->order);
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
