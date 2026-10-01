<?php

namespace Modules\Order\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Order\Delivery\DeliveryMoney;
use Modules\Order\Delivery\DeliveryWallet;
use Modules\Order\Enums\DeliveryStatus;
use Modules\Order\Events\DeliveryStatusChanged;
use Modules\Order\Models\OrderDelivery;
use Throwable;

/** Returns all unsettled or captured delivery funds after confirmed cancellation. */
final class SettleDeliveryWalletAfterCancellation
{
    public function __construct(private readonly DeliveryWallet $wallet) {}

    public function handle(DeliveryStatusChanged $event): void
    {
        if ($event->to !== DeliveryStatus::Cancelled) return;

        try {
            $delivery = OrderDelivery::query()->withoutGlobalScopes()
                ->where('tenant_id', $event->tenantId)
                ->whereKey($event->deliveryId)
                ->where('order_id', $event->orderId)
                ->first();
            if (! $delivery) return;

            $released = $this->wallet->releaseOutstanding(
                $event->tenantId,
                $event->deliveryId,
                $event->orderId,
                "delivery:{$event->deliveryId}:release-confirmed-cancellation",
                'Delivery cancellation was confirmed before the reserved provider charge was captured.',
            );

            $ledger = DB::table('delivery_wallet_transactions')
                ->where('tenant_id', $event->tenantId)
                ->where('order_delivery_id', $event->deliveryId)
                ->where('order_id', $event->orderId)
                ->selectRaw("SUM(CASE WHEN type = 'capture' THEN amount ELSE 0 END) captured")
                ->selectRaw("SUM(CASE WHEN type = 'refund' THEN amount ELSE 0 END) refunded")
                ->first();
            $captured = DeliveryMoney::decimal($ledger->captured ?? 0);
            $refunded = DeliveryMoney::decimal($ledger->refunded ?? 0);
            $reversal = DeliveryMoney::decimal(bcsub($captured, $refunded, 4));
            $credited = null;
            if (bccomp($reversal, '0', 4) > 0) {
                $reference = 'confirmed-cancellation:'.($delivery->provider ?: 'delivery').':'.($delivery->external_delivery_id ?: $delivery->id);
                $credited = $this->wallet->refundCapture(
                    $event->tenantId,
                    $event->deliveryId,
                    $event->orderId,
                    $reversal,
                    "delivery:{$event->deliveryId}:refund-confirmed-cancellation",
                    'Provider delivery was cancelled and its captured wallet charge was reversed.',
                    $reference,
                    null,
                );
            }

            if (($released && ! $released->_idempotent_replay) || ($credited && ! $credited->_idempotent_replay)) {
                activity('delivery_wallet')->event('delivery_wallet_cancellation_reversal')
                    ->performedOn($delivery)
                    ->withProperties([
                        'tenant_id' => $event->tenantId,
                        'order_id' => $event->orderId,
                        'delivery_id' => $event->deliveryId,
                        'provider_task_id' => $delivery->external_delivery_id,
                        'released_reservation' => $released?->amount,
                        'refunded_capture' => $credited?->amount,
                        'release_transaction_uuid' => $released?->uuid,
                        'refund_transaction_uuid' => $credited?->uuid,
                    ])->log('Confirmed delivery cancellation reversed the delivery wallet charge.');
            }
        } catch (Throwable $exception) {
            Log::error('Unable to reverse delivery wallet charge after confirmed cancellation.', [
                'tenant_id' => $event->tenantId,
                'delivery_id' => $event->deliveryId,
                'order_id' => $event->orderId,
                'exception_type' => $exception::class,
                'exception_message' => mb_substr($exception->getMessage(), 0, 500),
            ]);
        }
    }
}
