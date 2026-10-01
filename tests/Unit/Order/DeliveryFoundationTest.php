<?php

namespace Tests\Unit\Order;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Branch\Models\Branch;
use Modules\Order\Delivery\DeliveryCostCalculator;
use Modules\Order\Delivery\DeliveryLocation;
use Modules\Order\Delivery\DeliveryQuote;
use Modules\Order\Delivery\DeliveryQuoteSelector;
use Modules\Order\Delivery\UengageDeliveryProvider;
use Modules\Order\Delivery\ProviderUnavailable;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Jobs\AssignOrderDelivery;
use Modules\Order\Listeners\StartDeliveryAfterKitchenAcceptance;
use Modules\Order\Models\Order;
use Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService;
use Tests\TestCase;

class DeliveryFoundationTest extends TestCase
{
    public function test_distance_and_costs_are_provider_independent(): void
    {
        $origin = DeliveryLocation::fromAddress(['latitude' => 28.6139, 'longitude' => 77.2090]);
        $destination = DeliveryLocation::fromAddress(['latitude' => 28.6239, 'longitude' => 77.2090]);
        $this->assertNotNull($origin);
        $this->assertNotNull($destination);
        $this->assertGreaterThan(1, $origin->distanceTo($destination));
        $this->assertNull(DeliveryLocation::fromAddress(['address_line1' => 'Missing map pin']));
        $this->assertSame(['restaurant_contribution' => 15.0, 'platform_contribution' => 0.0, 'delivery_margin' => 0.0], (new DeliveryCostCalculator)->split(40, 55));
        $this->assertSame(15.0, (new DeliveryCostCalculator)->split(70, 55)['delivery_margin']);
    }

    public function test_quote_selection_filters_cost_eta_expiry_and_partner_codes(): void
    {
        $quotes = [
            new DeliveryQuote('a', 'A', 46, 31, 'q-a'),
            new DeliveryQuote('b', 'B', 51, 24, 'q-b'),
            new DeliveryQuote('c', 'C', 40, 55, 'q-c'),
            new DeliveryQuote('d', 'D', 30, 20, 'q-d', expiresAt: CarbonImmutable::yesterday()),
        ];
        $result = (new DeliveryQuoteSelector)->rank($quotes, [
            'delivery_selection_strategy' => 'cheapest',
            'delivery_provider_codes' => ['a', 'b', 'c'],
            'maximum_provider_delivery_cost' => 60,
            'maximum_delivery_eta_minutes' => 45,
        ], false);
        $this->assertSame(['a', 'b'], array_map(fn ($quote) => $quote->partnerCode, $result['ranked']));
        $this->assertSame('ETA_LIMIT', $result['audit'][2]['rejection_reason']);
        $this->assertSame('QUOTE_EXPIRED', $result['audit'][3]['rejection_reason']);
    }

    public function test_uengage_boundary_cannot_fabricate_quotes_or_bookings(): void
    {
        config()->set('delivery.integration_enabled', false);
        $provider = new UengageDeliveryProvider(
            new \Modules\Order\Delivery\UengageClient,
            new \Modules\Order\Delivery\UengageTaskPayload,
        );
        $point = new DeliveryLocation(28.6, 77.2);
        try {
            $provider->quotes($point, $point, 'ORDER-1', false);
            $this->fail('Provider returned fabricated quotes.');
        } catch (ProviderUnavailable $exception) {
            $this->assertSame('PROVIDER_INTEGRATION_DISABLED', $exception->reasonCode);
        }
    }

    public function test_kitchen_preparing_dispatches_only_delivery_after_commit(): void
    {
        config()->set('delivery.integration_enabled', true);
        Queue::fake();
        DB::shouldReceive('afterCommit')->once()->andReturnUsing(fn ($callback) => $callback());
        $order = new Order(['branch_id' => 5, 'type' => OrderType::Delivery, 'status' => OrderStatus::Preparing]);
        $order->id = 42;
        $order->setRelation('branch', new Branch(['tenant_id' => 9]));
        (new StartDeliveryAfterKitchenAcceptance)->handle(new OrderUpdateStatus($order, OrderStatus::Preparing));
        Queue::assertPushed(AssignOrderDelivery::class, fn ($job) => $job->tenantId === 9 && $job->orderId === 42);

        $pickup = new Order(['type' => OrderType::Pickup, 'status' => OrderStatus::Preparing]);
        (new StartDeliveryAfterKitchenAcceptance)->handle(new OrderUpdateStatus($pickup, OrderStatus::Preparing));
        Queue::assertPushed(AssignOrderDelivery::class, 1);
    }

    public function test_global_off_job_does_not_touch_orders_or_provider(): void
    {
        config()->set('delivery.integration_enabled', false);
        $entitlements = $this->createMock(EffectiveTenantEntitlementService::class);
        $entitlements->expects($this->never())->method('has');
        $provider = $this->createMock(\Modules\Order\Delivery\DeliveryProvider::class);
        $provider->expects($this->never())->method('quotes');
        (new AssignOrderDelivery(9, 42))->handle($entitlements, $provider, new DeliveryQuoteSelector, new DeliveryCostCalculator, new \Modules\Order\Delivery\DeliveryBookingGuard, new \Modules\Order\Delivery\DeliveryWallet);
        $this->assertTrue(true);
    }
}
