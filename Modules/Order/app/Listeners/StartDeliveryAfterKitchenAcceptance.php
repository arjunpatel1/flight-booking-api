<?php

namespace Modules\Order\Listeners;

use Illuminate\Support\Facades\DB;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Jobs\AssignOrderDelivery;

class StartDeliveryAfterKitchenAcceptance
{
    public function handle(OrderUpdateStatus $event): void
    {
        if ($event->status !== OrderStatus::Preparing || $event->order->type !== OrderType::Delivery
            || ! config('delivery.integration_enabled', false)) {
            return;
        }

        $tenantId = (int) $event->order->branch?->tenant_id;
        if ($tenantId < 1) return;
        $orderId = (int) $event->order->id;
        DB::afterCommit(fn () => AssignOrderDelivery::dispatch($tenantId, $orderId));
    }
}
