<?php

namespace Modules\Voice\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Notification\Enums\NotificationSeverity;
use Modules\Notification\Models\Notification;
use Modules\Notification\Services\NotificationServiceInterface;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Models\Order;
use Modules\Voice\Services\VoiceAnnouncementService;

class OrderStatusVoiceListener
{
    public function __construct(
        private VoiceAnnouncementService $voiceService,
        private NotificationServiceInterface $notificationService
    ) {
    }

    public function handle(OrderUpdateStatus $event): void
    {
        $order = $event->order;

        if (!$order->branch_id) {
            Log::warning('Voice status announcement skipped: missing branch_id.', ['order_id' => $order->id]);
            return;
        }

        $eventTypes = match ($event->status) {
            OrderStatus::Ready => ['KotReady', 'OrderReady'],
            default => [],
        };

        if (empty($eventTypes)) {
            return;
        }

        $order->loadMissing(['table', 'waiter', 'customer']);
        $variables = $this->extractOrderVariables($order);

        foreach ($eventTypes as $eventType) {
            $this->voiceService->triggerAnnouncement(
                (int) $order->branch_id,
                (int) $order->id,
                $eventType,
                $variables
            );
        }

        if ($event->status === OrderStatus::Ready) {
            $this->notifyWaiterOrderReady($order, $variables);
        }
    }

    private function extractOrderVariables(Order $order): array
    {
        return [
            'OrderId' => $order->id,
            'OrderNumber' => $order->order_number,
            'OrderType' => $order->type?->value ?? (string) $order->type,
            'TableNumber' => $order->table?->name ?? '',
            'WaiterName' => $order->waiter?->name ?? '',
            'CustomerName' => $order->customer?->name ?? '',
            'OrderAmount' => $order->total ? number_format($order->total->amount(), 2) : '',
        ];
    }

    private function notifyWaiterOrderReady(Order $order, array $variables): void
    {
        if (!$order->waiter) {
            return;
        }

        $exists = Notification::query()
            ->where('target_user_id', $order->waiter->id)
            ->where('type', 'order_ready')
            ->where('payload->order_id', $order->id)
            ->exists();

        if ($exists) {
            return;
        }

        $tableNumber = $variables['TableNumber'] ?: 'counter';
        $orderNumber = $variables['OrderNumber'] ?: $order->reference_no ?: $order->id;

        $this->notificationService->create([
            'title' => __('notification::notifications.order_ready.title', [
                'table' => $tableNumber,
            ]),
            'message' => __('notification::notifications.order_ready.message', [
                'order' => $orderNumber,
            ]),
            'type' => 'order_ready',
            'severity' => NotificationSeverity::Success->value,
            'icon' => 'tabler-bell-ringing',
            'color' => 'success',
            'payload' => [
                'order_id' => $order->id,
                'reference_no' => $order->reference_no,
                'order_number' => $order->order_number,
                'table_id' => $order->table_id,
                'table_name' => $variables['TableNumber'],
                'status' => OrderStatus::Ready->value,
            ],
        ], $order->waiter);
    }
}
