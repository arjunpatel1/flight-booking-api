<?php

namespace Tests\Unit\Order;

use Modules\Order\Delivery\DeliveryReadModel;
use Modules\Order\Delivery\DeliveryStateMachine;
use Modules\Order\Delivery\ProviderUnavailable;
use Modules\Order\Delivery\UengageWebhookAuthenticator;
use Modules\Order\Delivery\UengageWebhookProcessor;
use Modules\Order\Enums\DeliveryStatus;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDelivery;
use Tests\TestCase;

class DeliveryStateMachineSecurityTest extends TestCase
{
    public function test_forward_progress_is_explicit_and_terminal_states_cannot_regress(): void
    {
        $machine = new DeliveryStateMachine;
        $this->assertTrue($machine->can(DeliveryStatus::WaitingForAssignment, DeliveryStatus::FetchingQuotes));
        $this->assertTrue($machine->can(DeliveryStatus::BookingPending, DeliveryStatus::Investigation));
        $this->assertTrue($machine->can(DeliveryStatus::PickedUp, DeliveryStatus::Delivered));
        $this->assertFalse($machine->can(DeliveryStatus::Delivered, DeliveryStatus::PickedUp));
        $this->assertFalse($machine->can(DeliveryStatus::Cancelled, DeliveryStatus::RiderAssigned));
        $this->assertFalse($machine->can(DeliveryStatus::Rto, DeliveryStatus::Delivered));
        $this->assertFalse($machine->can(DeliveryStatus::Investigation, DeliveryStatus::ManualReviewRequired));
        $this->assertFalse($machine->can(DeliveryStatus::ManualReviewRequired, DeliveryStatus::FetchingQuotes));
        $cancelledDelivery = new OrderDelivery(['status' => DeliveryStatus::Cancelled, 'mode' => 'third_party', 'external_delivery_id' => 'task-1']);
        $order = new Order(['type' => OrderType::Delivery]);
        $order->setRelation('delivery', $cancelledDelivery);
        $this->assertFalse($order->deliveryIsProviderManaged());
    }

    public function test_every_state_pair_matches_the_explicit_transition_graph(): void
    {
        $allowed = [
            'waiting_for_assignment' => ['fetching_quotes', 'cancelled', 'failed', 'manual_review_required'],
            'fetching_quotes' => ['serviceability_checked', 'quoted', 'failed', 'manual_review_required', 'cancelled'],
            'serviceability_checked' => ['quoted', 'booking_pending', 'failed', 'manual_review_required', 'cancelled'],
            'quoted' => ['booking_pending', 'failed', 'manual_review_required', 'cancelled'],
            'assigning' => ['booking_pending', 'booked', 'rider_searching', 'failed', 'investigation', 'manual_review_required', 'cancelled'],
            'booking_pending' => ['booked', 'rider_searching', 'failed', 'investigation', 'manual_review_required', 'cancel_pending', 'cancelled'],
            'booked' => ['rider_searching', 'rider_assigned', 'cancel_pending', 'cancelled', 'investigation'],
            'rider_searching' => ['rider_assigned', 'cancel_pending', 'cancelled', 'rto', 'failed', 'investigation', 'manual_review_required'],
            'rider_assigned' => ['arrived_at_pickup', 'picked_up', 'in_transit', 'arrived_at_customer', 'delivered', 'cancel_pending', 'cancelled', 'rto', 'failed', 'investigation', 'manual_review_required'],
            'arrived_at_pickup' => ['picked_up', 'in_transit', 'arrived_at_customer', 'delivered', 'cancel_pending', 'cancelled', 'rto', 'failed', 'investigation', 'manual_review_required'],
            'picked_up' => ['in_transit', 'arrived_at_customer', 'delivered', 'rto', 'investigation', 'manual_review_required'],
            'in_transit' => ['arrived_at_customer', 'delivered', 'rto', 'investigation', 'manual_review_required'],
            'arrived_at_customer' => ['delivered', 'rto', 'investigation', 'manual_review_required'],
            'cancel_pending' => ['cancelled', 'investigation', 'manual_review_required'],
            'rto' => ['rto_completed', 'investigation', 'manual_review_required'],
            'rto_completed' => ['returned_after_delivery', 'investigation'],
            'returned_after_delivery' => ['investigation'],
            'investigation' => [],
            'manual_review_required' => ['cancel_pending', 'cancelled', 'failed', 'investigation'],
            'delivered' => [], 'cancelled' => [], 'failed' => [],
        ];
        $machine = new DeliveryStateMachine;
        foreach (DeliveryStatus::cases() as $from) {
            foreach (DeliveryStatus::cases() as $to) {
                $expected = $from === $to || in_array($to->value, $allowed[$from->value], true);
                $this->assertSame($expected, $machine->can($from, $to), "{$from->value} -> {$to->value}");
            }
        }
    }

    public function test_duplicate_status_cannot_rewrite_completed_delivery_details(): void
    {
        $delivery = new OrderDelivery([
            'status' => DeliveryStatus::Delivered,
            'rider_name' => 'Original rider',
            'tracking_url' => 'https://tracking.example.test/original',
        ]);

        (new DeliveryStateMachine)->transition($delivery, DeliveryStatus::Delivered, [
            'rider_name' => 'Replacement rider',
            'tracking_url' => 'https://different.example.test/new',
        ]);

        $this->assertSame('Original rider', $delivery->rider_name);
        $this->assertSame('https://tracking.example.test/original', $delivery->tracking_url);
    }

    public function test_unsigned_callback_cannot_reach_state_processing_even_when_gate_is_on(): void
    {
        config(['delivery.webhook_enabled' => true]);
        try {
            app(UengageWebhookProcessor::class)->process('{"status_code":"DELIVERED"}', []);
            $this->fail('Unsigned callback was accepted.');
        } catch (ProviderUnavailable $exception) {
            $this->assertSame('WEBHOOK_AUTHENTICATION_FAILED', $exception->reasonCode);
        }
    }

    public function test_callback_requires_the_exact_configured_bearer_token(): void
    {
        $token = str_repeat('a', 64);
        config(['delivery.webhook_token_hash' => hash('sha256', $token)]);
        $authenticator = app(UengageWebhookAuthenticator::class);

        $this->assertTrue($authenticator->authenticate('{}', ['authorization' => ["Bearer {$token}"]]));
        $shortProviderToken = str_repeat('c', 24);
        config(['delivery.webhook_token_hash' => hash('sha256', $shortProviderToken)]);
        $this->assertTrue($authenticator->authenticate('{}', ['authorization' => ["Bearer {$shortProviderToken}"]]));
        $this->assertFalse($authenticator->authenticate('{}', ['authorization' => ['Bearer '.str_repeat('b', 64)]]));
        $this->assertFalse($authenticator->authenticate('{}', []));
    }

    public function test_webhook_gate_is_off_by_default(): void
    {
        config(['delivery.webhook_enabled' => false]);
        try {
            app(UengageWebhookProcessor::class)->process('{}', []);
            $this->fail('Disabled webhook ingestion was accepted.');
        } catch (ProviderUnavailable $exception) {
            $this->assertSame('PROVIDER_WEBHOOK_DISABLED', $exception->reasonCode);
        }
    }

    public function test_uengage_callback_normalizes_delivery_otp_aliases_without_using_pickup_otp(): void
    {
        $processor = app(UengageWebhookProcessor::class);
        $method = new \ReflectionMethod($processor, 'deliveryOtpFrom');

        $this->assertSame('9396', $method->invoke($processor, ['drop_otp' => 9396]));
        $this->assertSame('5861', $method->invoke($processor, ['task_details' => ['dropOTPCode' => '5861']]));
        $this->assertSame('4827', $method->invoke($processor, ['delivery' => ['otp' => '4827']]));
        $this->assertNull($method->invoke($processor, ['task_details' => ['pickup_otp' => '1111']]));
    }

    public function test_provider_booking_makes_tenant_delivery_controls_read_only(): void
    {
        $delivery = new OrderDelivery([
            'mode' => 'third_party',
            'external_delivery_id' => 'task-safe-1',
            'status' => DeliveryStatus::RiderAssigned,
        ]);
        $view = DeliveryReadModel::admin($delivery);

        $this->assertTrue($view['provider_managed']);
        $this->assertFalse($view['tenant_delivery_actions_allowed']);
        $this->assertFalse($view['redispatch_allowed']);

        $cancelled = new OrderDelivery([
            'mode' => 'third_party', 'external_delivery_id' => 'task-cancelled-1',
            'status' => DeliveryStatus::Cancelled, 'cancelled_at' => now(),
        ]);
        $cancelledView = DeliveryReadModel::admin($cancelled);
        $this->assertFalse($cancelledView['provider_managed']);
        $this->assertTrue($cancelledView['tenant_delivery_actions_allowed']);
        $this->assertTrue($cancelledView['redispatch_allowed']);
        $customerCancelledView = DeliveryReadModel::customer($cancelled);
        $this->assertNull($customerCancelledView['rider_name']);
        $this->assertNull($customerCancelledView['tracking_url']);

        $manual = new OrderDelivery(['mode' => 'manual', 'status' => DeliveryStatus::WaitingForAssignment]);
        $manualView = DeliveryReadModel::admin($manual);
        $this->assertFalse($manualView['provider_managed']);
        $this->assertTrue($manualView['tenant_delivery_actions_allowed']);

        $partner = new OrderDelivery(['mode' => 'partner_api', 'status' => DeliveryStatus::RiderSearching]);
        $partnerView = DeliveryReadModel::admin($partner);
        $this->assertTrue($partnerView['provider_managed']);
        $this->assertFalse($partnerView['tenant_delivery_actions_allowed']);
    }

    public function test_customer_tracking_hides_provider_placeholder_rider_data_until_real_assignment(): void
    {
        $delivery = new OrderDelivery([
            'status' => DeliveryStatus::RiderSearching,
            'rider_name' => 'Not Provided',
            'rider_phone' => '9999999999',
            'tracking_url' => 'https://uen.io/track/FX7CGIX26RAMLZ2',
        ]);

        $searching = DeliveryReadModel::customer($delivery);
        $this->assertNull($searching['rider_name']);
        $this->assertNull($searching['rider_phone']);
        $this->assertNull($searching['delivery_otp']);
        $this->assertSame('https://uen.io/track/FX7CGIX26RAMLZ2', $searching['tracking_url']);

        $delivery->fill([
            'status' => DeliveryStatus::RiderAssigned,
            'rider_name' => 'Verified Rider',
            'rider_phone' => '+918500434823',
            'delivery_otp' => '5861',
        ]);
        $assigned = DeliveryReadModel::customer($delivery);
        $this->assertSame('Verified Rider', $assigned['rider_name']);
        $this->assertSame('+918500434823', $assigned['rider_phone']);
        $this->assertSame('5861', $assigned['delivery_otp']);
    }

    public function test_admin_delivery_attempt_history_preserves_task_ids_and_hides_cost_without_permission(): void
    {
        $delivery = new OrderDelivery([
            'status' => DeliveryStatus::RiderAssigned,
            'external_delivery_id' => 'TASK-NEW',
            'provider_final_cost' => 81.50,
            'assignment_started_at' => now(),
            'attempt_history' => [[
                'attempt_number' => 1,
                'task_id' => 'TASK-OLD',
                'status' => 'cancelled',
                'provider_final_cost' => 76.70,
                'cancelled_at' => now()->subMinute()->toIso8601String(),
            ]],
        ]);

        $restricted = DeliveryReadModel::admin($delivery, false);
        $this->assertSame(['TASK-OLD', 'TASK-NEW'], collect($restricted['delivery_attempts'])->pluck('task_id')->all());
        $this->assertArrayNotHasKey('provider_final_cost', $restricted['delivery_attempts'][0]);
        $this->assertArrayNotHasKey('provider_final_cost', $restricted['delivery_attempts'][1]);

        $allowed = DeliveryReadModel::admin($delivery, true);
        $this->assertSame(76.7, (float) $allowed['delivery_attempts'][0]['provider_final_cost']);
        $this->assertSame(81.5, (float) $allowed['delivery_attempts'][1]['provider_final_cost']);
        $this->assertTrue($allowed['delivery_attempts'][1]['current']);
    }

    public function test_restaurant_cannot_advance_or_cancel_partner_managed_delivery(): void
    {
        $order = new Order(['type' => OrderType::Delivery, 'status' => OrderStatus::Ready,
            'payment_status' => OrderPaymentStatus::Unpaid]);
        $order->setRelation('delivery', new OrderDelivery(['mode' => 'partner_api',
            'status' => DeliveryStatus::RiderAssigned]));

        $this->assertTrue($order->deliveryIsProviderManaged());
        $this->assertFalse($order->allowUpdateStatus());
        $this->assertFalse($order->cancelIsAllowed());

        $manual = new Order(['type' => OrderType::Delivery, 'status' => OrderStatus::Ready,
            'payment_status' => OrderPaymentStatus::Unpaid]);
        $manual->setRelation('delivery', new OrderDelivery(['mode' => 'manual',
            'status' => DeliveryStatus::WaitingForAssignment]));
        $this->assertFalse($manual->deliveryIsProviderManaged());
        $this->assertTrue($manual->cancelIsAllowed());
    }
}
