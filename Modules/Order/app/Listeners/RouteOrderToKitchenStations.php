<?php

namespace Modules\Order\Listeners;

use Illuminate\Support\Facades\Cache;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Pos\Services\KitchenStation\KitchenStationServiceInterface;

class RouteOrderToKitchenStations
{
    /**
     * Create a new event listener instance.
     */
    public function __construct(
        private readonly KitchenStationServiceInterface $stationService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(OrderCreated|OrderUpdateStatus $event): void
    {
        $order = $event->order;

        if ($event instanceof OrderUpdateStatus
            && ($event->status !== OrderStatus::Confirmed || ! in_array($event->note, [
                'KITCHEN_RELEASED_AFTER_APPROVAL',
                'KITCHEN_RELEASED_AFTER_PAYMENT',
            ], true))) {
            return;
        }

        // Kitchen Viewer caches each staff member's queue.  A QR order must
        // invalidate that cache at creation time or the new ticket can remain
        // invisible for up to a minute after a successful customer checkout.
        Cache::tags(['orders', 'kitchen'])->flush();

        // Load products relationship
        $order->load('products');

        // Only route orders that should be displayed in kitchen
        if (! $order->kitchen_display) {
            return;
        }

        // Only route orders with products
        if ($order->products->isEmpty()) {
            return;
        }

        // Route each order product to appropriate kitchen stations
        $orderProductIds = $order->products->pluck('id')->toArray();

        $this->stationService->routeOrderProducts(
            $orderProductIds,
            $order->branch_id
        );
    }
}
