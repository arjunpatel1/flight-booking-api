<?php

namespace Modules\Order\Listeners;

use Modules\Invoice\Services\CreateInvoice\CreateInvoiceServiceInterface;
use Modules\Order\Events\OrderMergeBillingPaid;
use Modules\Order\Events\OrderPaid;
use Modules\Order\Models\Order;
use Throwable;

class CreateOrderPaidInvoice
{
    /**
     * Handle the event.
     * @throws Throwable
     */
    public function handle(OrderPaid|OrderMergeBillingPaid $event): void
    {
        $order = $event instanceof OrderMergeBillingPaid
            ? Order::query()->where('table_merge_id', $event->tableMerge->id)->first()
            : $event->order;

        app(CreateInvoiceServiceInterface::class)->create($order);
    }
}
