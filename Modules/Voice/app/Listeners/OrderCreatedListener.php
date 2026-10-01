<?php

namespace Modules\Voice\Listeners;

use Modules\Order\Events\OrderCreated;
use Modules\Order\Models\Order;
use Modules\Voice\Services\VoiceAnnouncementService;
use Illuminate\Support\Facades\Log;

class OrderCreatedListener
{
    public function __construct(
        private VoiceAnnouncementService $voiceService
    ) {}

    /**
     * Handle the order created event.
     */
    public function handle(OrderCreated $event): void
    {
        try {
            $order = $event->order;

            if (!$order->branch_id) {
                Log::warning("Voice announcement skipped for order {$order->id}: missing branch_id");
                return;
            }

            $order->loadMissing(['table', 'waiter', 'customer', 'aggregatorOrderMapping.integration']);
            
            // Extract variables for voice announcement
            $variables = $this->extractOrderVariables($order);
            
            // Determine event type based on order type
            $eventType = $this->determineEventType($order);
            
            // Trigger voice announcement
            $this->voiceService->triggerAnnouncement(
                $order->branch_id,
                $order->id,
                $eventType,
                $variables
            );
            
            Log::info("Voice announcement triggered for order {$order->id}");
        } catch (\Exception $e) {
            Log::error("Failed to trigger voice announcement for order: " . $e->getMessage());
        }
    }

    /**
     * Extract variables from order for voice announcement.
     */
    protected function extractOrderVariables(Order $order): array
    {
        // Always supply every key. A missing key leaves the raw "{TableNumber}"
        // placeholder in the template, which then gets read aloud verbatim.
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

    /**
     * Determine voice event type based on order.
     */
    protected function determineEventType(Order $order): string
    {
        $mapping = $order->relationLoaded('aggregatorOrderMapping')
            ? $order->aggregatorOrderMapping
            : $order->aggregatorOrderMapping()->with('integration')->first();

        $provider = $mapping?->integration?->provider?->value;

        if ($provider === 'swiggy') {
            return 'SwiggyOrder';
        }

        if ($provider === 'zomato') {
            return 'ZomatoOrder';
        }

        return 'NewOrder';
    }
}
