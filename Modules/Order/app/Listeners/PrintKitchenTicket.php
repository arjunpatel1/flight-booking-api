<?php

namespace Modules\Order\Listeners;

use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Events\OrderPaid;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Jobs\DispatchPrintJob;
use Throwable;

class PrintKitchenTicket
{
    public int $tries = 3;

    /**
     * Handle the event.
     *
     * @throws Throwable
     */
    public function handle(OrderCreated|OrderUpdateStatus|OrderPaid $event): void
    {
        $order = $event->order;
        $configuredMode = setting('customer_order_auto_print_mode');
        $mode = is_string($configuredMode) && $configuredMode !== ''
            ? $configuredMode
            : ($event instanceof OrderCreated
                ? ((bool) setting('printer_auto_kot_enabled', false) ? 'kot' : 'disabled')
                : 'kot');

        // A tax invoice does not exist until payment is recorded. Defer the
        // customer copy to OrderPaid, after CreateOrderPaidInvoice has created
        // the immutable invoice, instead of logging a misleading "Not found"
        // failure when an unpaid order is accepted into the kitchen.
        if ($event instanceof OrderPaid) {
            if (in_array($mode, ['invoice', 'kot_invoice'], true)) {
                DispatchPrintJob::dispatchSyncAfterCommit($order->id, PrintContentType::Invoice);
            }

            return;
        }

        $status = $event instanceof OrderCreated ? $event->order->status : $event->status;

        if ($status !== OrderStatus::Confirmed || ! $order->isScheduledForToday()) {
            return;
        }
        if ($event instanceof OrderCreated && ! $event->shouldPrintKitchenTicket) {
            return;
        }

        if (in_array($mode, ['kot', 'kot_invoice'], true)) {
            // KOT uses the lightweight ESC/POS text renderer, so creating its
            // printer job immediately avoids the visible queue-worker delay.
            DispatchPrintJob::dispatchSyncAfterCommit($order->id, PrintContentType::Kitchen);
        }

    }
}
