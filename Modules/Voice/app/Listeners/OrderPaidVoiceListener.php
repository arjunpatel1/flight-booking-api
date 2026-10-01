<?php

namespace Modules\Voice\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Order\Events\OrderPaid;
use Modules\Order\Models\Order;
use Modules\Voice\Services\VoiceAnnouncementService;

class OrderPaidVoiceListener
{
    public function __construct(
        private VoiceAnnouncementService $voiceService
    ) {
    }

    public function handle(OrderPaid $event): void
    {
        $order = $event->order;

        if (!$order->branch_id) {
            Log::warning('Voice payment announcement skipped: missing branch_id.', ['order_id' => $order->id]);
            return;
        }

        $order->loadMissing(['table', 'waiter', 'customer']);

        $this->voiceService->triggerAnnouncement(
            (int) $order->branch_id,
            (int) $order->id,
            'PaymentSuccess',
            [
                'OrderId' => $order->id,
                'OrderNumber' => $order->order_number,
                'OrderType' => $order->type?->value ?? (string) $order->type,
                'TableNumber' => $order->table?->name ?? '',
                'WaiterName' => $order->waiter?->name ?? '',
                'CustomerName' => $order->customer?->name ?? '',
                'OrderAmount' => $order->total ? number_format($order->total->amount(), 2) : '',
            ]
        );
    }
}
