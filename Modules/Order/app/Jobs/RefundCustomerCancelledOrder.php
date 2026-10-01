<?php

namespace Modules\Order\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Models\Order;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Enums\PaymentType;
use Modules\Payment\Services\Payment\PaymentServiceInterface;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use RuntimeException;

final class RefundCustomerCancelledOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 300, 900];

    public function __construct(public readonly int $orderId, public readonly int $tenantId)
    {
        $this->onQueue('payments')->afterCommit();
    }

    public function handle(TenantContext $context, SettingServiceInterface $settings, PaymentServiceInterface $payments): void
    {
        $previous = $context->id();
        $context->setId($this->tenantId);
        $settings->refreshSettingBinding();

        try {
            $order = Order::query()->withoutGlobalScopes()
                ->whereKey($this->orderId)
                ->whereHas('branch', fn ($query) => $query->where('tenant_id', $this->tenantId))
                ->firstOrFail();
            if ($order->status === OrderStatus::Refunded) {
                return;
            }
            if ($order->status !== OrderStatus::Cancelled) {
                throw new RuntimeException('Only a cancelled order can be refunded.');
            }

            $payment = $order->payments()
                ->where('type', PaymentType::Payment->value)
                ->whereIn('status', [PaymentStatus::Completed->value, PaymentStatus::Refunded->value])
                ->whereNotNull('gateway')
                ->latest('id')
                ->first();
            if (! $payment) {
                throw new RuntimeException('The completed gateway payment for this cancelled order was not found.');
            }

            $payments->refundPayment($payment, 'Customer cancelled before kitchen preparation started.');

            DB::transaction(function () use ($order): void {
                $locked = Order::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);
                if ($locked->status === OrderStatus::Refunded) {
                    return;
                }
                $locked->update(['status' => OrderStatus::Refunded]);
                $locked->storeStatusLog(OrderStatus::Refunded, note: 'CUSTOMER_CANCELLATION_REFUNDED');
                event(new OrderUpdateStatus(order: $locked, status: OrderStatus::Refunded, note: 'CUSTOMER_CANCELLATION_REFUNDED'));
            });
        } finally {
            $context->setId($previous);
            $settings->refreshSettingBinding();
        }
    }
}
