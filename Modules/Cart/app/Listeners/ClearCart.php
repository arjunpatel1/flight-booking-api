<?php

namespace Modules\Cart\Listeners;

use Exception;
use Modules\Cart\Facades\Cart;
use Modules\Order\Enums\OrderType;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Events\OrderUpdated;

class ClearCart
{
    /**
     * Handle the event.
     */
    public function handle(OrderCreated|OrderUpdated $event): void
    {
        Cart::clear();

        if ($event->order->type != OrderType::DineIn) {
            try {
                Cart::addOrderType($event->order->type);
                Cart::addBranch($event->order->branch);
            } catch (Exception) {
            }
        }
    }
}
