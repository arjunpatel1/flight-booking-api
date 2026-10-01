<?php

namespace Modules\Order\Listeners;

use Illuminate\Support\Facades\DB;
use Modules\Order\Delivery\DeliveryWallet;
use Modules\Order\Enums\DeliveryStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Models\OrderDelivery;

class MarkDeliveryAfterOrderCancellation
{
    public function __construct(private readonly DeliveryWallet $wallet) {}

    public function handle(OrderUpdateStatus $event): void
    {
        if (! in_array($event->status, [OrderStatus::Cancelled, OrderStatus::Refunded], true)) return;

        DB::transaction(function () use ($event): void {
            // Keep the same order -> delivery lock sequence used by assignment.
            $order = $event->order->newQuery()->withoutGlobalScopes()->whereKey($event->order->id)->lockForUpdate()->first();
            if (! $order) return;
            $delivery = OrderDelivery::query()->where('order_id', $order->id)->lockForUpdate()->first();
            if (! $delivery || in_array($delivery->status, [DeliveryStatus::Delivered, DeliveryStatus::Cancelled], true)) return;

            if ($delivery->external_delivery_id) {
                // There is no verified uEngage cancellation contract yet. Never
                // mark an external booking cancelled without provider confirmation.
                $delivery->fill([
                    'status' => DeliveryStatus::ManualReviewRequired,
                    'assignment_status' => 'external_cancellation_required',
                    'failure_code' => 'EXTERNAL_CANCELLATION_REQUIRED',
                    'failure_reason' => 'Order cancelled. Contact the provider to cancel its existing booking.',
                    'assignment_token' => null,
                ])->save();
                return;
            }

            if ($delivery->booking_requested_at) {
                // Once provider transmission starts, cancellation cannot prove
                // that no external task exists without provider reconciliation.
                $delivery->fill([
                    'status' => DeliveryStatus::Investigation,
                    'assignment_status' => 'unknown_provider_result',
                    'failure_code' => 'ORDER_CANCELLED_DURING_BOOKING',
                    'failure_reason' => 'Order cancelled while provider booking may be in progress. Verify whether an external task exists.',
                    'assignment_token' => null,
                ])->save();
                return;
            }

            // A claim and wallet reservation can exist briefly before provider
            // transmission. Cancellation is definitive at this phase, so
            // return the reservation rather than inventing an incident.
            $this->wallet->releaseOutstanding(
                (int) $delivery->tenant_id,
                (int) $delivery->id,
                (int) $order->id,
                'delivery:'.$delivery->id.':release-order-cancelled-before-transmission',
                'Order was cancelled before provider transmission started.',
            );

            $delivery->fill([
                'status' => DeliveryStatus::Cancelled,
                'assignment_status' => 'cancelled',
                'assignment_token' => null,
                'cancelled_at' => now(),
            ])->save();
        });
    }
}
