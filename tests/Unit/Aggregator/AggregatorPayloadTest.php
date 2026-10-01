<?php

namespace Tests\Unit\Aggregator;

use Modules\Aggregator\DTO\AggregatorOrderPayload;
use Modules\Aggregator\DTO\AggregatorWebhookPayload;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Tests\TestCase;

class AggregatorPayloadTest extends TestCase
{
    public function test_order_payload_is_normalized_to_internal_contract(): void
    {
        $order = new Order([
            'branch_id' => 12,
            'reference_no' => 'ORD-TEST',
            'order_number' => '1001',
            'status' => OrderStatus::Pending,
            'type' => OrderType::Takeaway,
            'payment_status' => OrderPaymentStatus::Unpaid,
            'currency' => 'INR',
            'currency_rate' => 1,
            'subtotal' => 250,
            'total' => 300,
            'guest_count' => 2,
            'notes' => 'No onions',
            'order_date' => today(),
        ]);
        $order->id = 88;

        $payload = AggregatorOrderPayload::fromOrder($order, 'order_created');

        $this->assertSame('internal.v1', $payload['version']);
        $this->assertSame('order_created', $payload['event']);
        $this->assertSame(88, $payload['order_id']);
        $this->assertSame(12, $payload['branch_id']);
        $this->assertSame(OrderStatus::Pending->value, $payload['status']);
        $this->assertSame(OrderType::Takeaway->value, $payload['type']);
        $this->assertSame('INR', $payload['currency']);
    }

    public function test_webhook_payload_is_normalized_and_keeps_raw_payload(): void
    {
        $payload = [
            'event_type' => 'order.ready',
            'event_id' => 'evt_ready',
            'data' => [
                'external_order_id' => 'external-1001',
                'status' => 'ready',
            ],
        ];

        $normalized = AggregatorWebhookPayload::normalize($payload);

        $this->assertSame('internal.webhook.v1', $normalized['version']);
        $this->assertSame('order.ready', $normalized['event_type']);
        $this->assertSame('evt_ready', $normalized['external_event_id']);
        $this->assertSame('external-1001', $normalized['external_order_id']);
        $this->assertSame($payload, $normalized['raw']);
    }

    public function test_webhook_payload_supports_alternate_id_and_type_keys(): void
    {
        $normalized = AggregatorWebhookPayload::normalize([
            'type' => 'order.delivered',
            'id' => 'evt_delivered',
            'payload' => [
                'external_order_id' => 'external-2002',
            ],
        ]);

        $this->assertSame('order.delivered', $normalized['event_type']);
        $this->assertSame('evt_delivered', $normalized['external_event_id']);
        $this->assertSame('external-2002', $normalized['external_order_id']);
    }
}
