<?php

namespace Modules\Order\Support;

use Modules\Order\Models\Order;
use Modules\Payment\Enums\PaymentType;

final class OrderPaymentPresenter
{
    public function concise(Order $order): string
    {
        $order->loadMissing('payments');
        $payment = $order->payments
            ->reject(fn ($payment) => $payment->type === PaymentType::Refund)
            ->sortByDesc('id')
            ->first();

        $gatewayMethod = str((string) data_get($payment?->meta, 'razorpay_method'))->squish()->lower()->toString();
        $method = match ($gatewayMethod) {
            'netbanking' => 'Netbanking',
            'upi' => 'UPI',
            'card' => 'Card',
            'wallet' => 'Wallet',
            default => match ($payment?->method?->value ?? strtolower((string) data_get($order->fulfilmentDetails(), 'payment_method'))) {
                'cash', 'cash_on_delivery', 'cod' => 'Cash on delivery',
                'upi' => 'UPI',
                'card' => 'Card',
                'bank_transfer' => 'Bank transfer',
                'mobile_wallet', 'wallet' => 'Wallet',
                'razorpay' => 'Paid online',
                default => 'Payment pending',
            },
        };

        return $order->payment_status->isPaid() ? $method.' · Paid' : $method;
    }
}
