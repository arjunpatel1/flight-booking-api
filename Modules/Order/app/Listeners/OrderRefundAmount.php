<?php

namespace Modules\Order\Listeners;

use Modules\Currency\Models\CurrencyRate;
use Modules\Order\Events\OrderVoided;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Enums\PaymentType;
use Modules\Payment\Services\Payment\PaymentServiceInterface;
use Throwable;

class OrderRefundAmount
{
    /**
     * Handle the event.
     *
     * @param OrderVoided $event
     *
     * @throws Throwable
     */
    public function handle(OrderVoided $event): void
    {
        if (!$event->order->hasRefundAmount()) {
            return;
        }

        abort_if(
            is_null($event->refundPaymentMethod),
            400,
            __("order::messages.refund_payment_method_required")
        );

        abort_if(
            is_null($event->posSession),
            400,
            __("pos::messages.no_active_session", [
                "action" => __("admin::resource.system_refund", ["resource" => __("order::orders.order")])
            ])
        );

        $gatewayPayment = $event->order->payments()
            ->where('type', PaymentType::Payment->value)
            ->where('status', PaymentStatus::Completed->value)
            ->whereNotNull('gateway')
            ->latest('id')
            ->first();
        if ($gatewayPayment) {
            app(PaymentServiceInterface::class)->refundPayment(
                $gatewayPayment,
                $event->note ?: 'Order cancelled after confirmed delivery cancellation.',
            );
            return;
        }

        $amount = $event->order->getRefundedAmount()->round()->amount();

        if ($amount <= 0) {
            return;
        }

        $event->order->storePayment([
            "type" => PaymentType::Refund->value,
            'cashier_id' => auth()->id(),
            'method' => $event->refundPaymentMethod->value,
            'amount' => $amount,
            'currency_rate' => CurrencyRate::for($event->order->currency),
            'session' => $event->posSession,
            'notes' => $event->note
        ]);
    }
}
