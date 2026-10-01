<?php

namespace Modules\Order\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;
use Modules\Notification\Models\Notification;
use Modules\Notification\Services\NotificationServiceInterface;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Support\OrderSourcePresenter;
use Modules\Order\Support\TenantStaffRecipients;
use Modules\User\Models\User;

class NotifyTenantAdminsOfNewOrder implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly NotificationServiceInterface $notifications) {}

    public function handle(OrderCreated $event): void
    {
        try {
            $order = $event->order->loadMissing(['branch:id,tenant_id,name', 'customer:id,name,phone', 'aggregatorOrderMapping.integration:id,provider,name', 'partnerApiOrderMapping:id,order_id', 'whatsAppOrderSession:id,order_id']);
            $source = OrderSourcePresenter::make($order);
            // Separate channel: in-app/source switches must not suppress phone alerts.
            if ($order->branch?->tenant_id) {
                \Modules\Order\Jobs\SendStaffOrderPhoneAlerts::dispatch($order->id, $order->branch->tenant_id);
            }
            $sourceSetting = match ($source['source_type']) {
                'whatsapp' => 'order_notifications_whatsapp_enabled',
                'customer_app', 'customer_web', 'qr' => 'order_notifications_customer_enabled',
                'waiter_app' => 'order_notifications_waiter_enabled',
                default => 'order_notifications_other_enabled',
            };
            if (! $this->enabledByDefault('notifications_enabled')
                || ! $this->enabledByDefault('notifications_in_app_enabled')
                || ! $this->enabledByDefault($sourceSetting)) {
                return;
            }
            $tenantId = $order->branch?->tenant_id;
            if (! $tenantId) {
                return;
            }

            $actionUrl = "/admin/orders/{$order->id}/show";
            TenantStaffRecipients::withAnyPermission((int) $tenantId, ['admin.orders.index', 'admin.orders.show'])
                ->each(function (User $user) use ($actionUrl, $order, $source): void {
                    // Payment verification intentionally re-emits OrderCreated to
                    // release paid orders to the kitchen. Keep the staff inbox
                    // idempotent when the same order lifecycle event is replayed.
                    if (Notification::query()->where('target_user_id', $user->id)
                        ->where('type', 'order_created')->where('action_url', $actionUrl)->exists()) {
                        return;
                    }
                    $this->notifications->create([
                        'title' => "New {$source['source_label']} Order",
                        'message' => sprintf(
                            'Order #%s · %s · %s. Review payment and items before preparing.',
                            $order->order_number,
                            $order->customer?->name ?: 'Guest customer',
                            $order->total->format(),
                        ),
                        'type' => 'order_created',
                        'severity' => 'info',
                        'icon' => $source['source_type'] === 'whatsapp' ? 'tabler-brand-whatsapp' : ($source['source_type'] === 'qr' ? 'tabler-qrcode' : 'tabler-receipt'),
                        'color' => $source['source_type'] === 'whatsapp' ? 'success' : 'primary',
                        'action_url' => $actionUrl,
                        'payload' => [
                            'reference_no' => $order->reference_no,
                            'order_number' => $order->order_number,
                            'customer_name' => $order->customer?->name ?: 'Guest customer',
                            'customer_phone' => $order->customer?->phone,
                            'total_formatted' => $order->total->format(),
                            'branch_id' => $order->branch_id,
                            'branch_name' => $order->branch?->name,
                            'source_type' => $source['source_type'],
                            'source_label' => $source['source_label'],
                        ],
                    ], $user);
                });
        } catch (\Throwable $exception) {
            // Notifications are supplementary. A transient notification or
            // permission-store failure must never roll back a valid order.
            Log::warning('Unable to notify tenant administrators about a new order.', [
                'order_id' => $event->order->getKey(),
                'reference_no' => $event->order->reference_no,
                'exception' => $exception,
            ]);
        }
    }

    private function enabledByDefault(string $key): bool
    {
        $value = setting($key);

        return $value === null || filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
