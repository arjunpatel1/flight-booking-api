<?php

namespace Tests\Unit\Order;

use Mockery;
use Modules\Branch\Models\Branch;
use Modules\Order\Delivery\CustomerDeliveryQuote;
use Modules\Order\Delivery\DeliveryCommercialTerms;
use Modules\Order\Delivery\DeliveryProvider;
use Modules\Order\Delivery\DeliveryQuote;
use Modules\Order\Delivery\ThirdPartyCustomerPricing;
use Tests\TestCase;

class ThirdPartyCustomerPricingTest extends TestCase
{
    public function test_missing_slabs_use_verified_provider_quote_plus_platform_fee(): void
    {
        $this->fakeSettings([
            'third_party_delivery_enabled' => true,
            'delivery_platform_fee_per_order' => 1,
        ]);
        $branch = new Branch(['latitude' => 0, 'longitude' => 0, 'delivery_radius_km' => 8]);
        $address = ['latitude' => rad2deg(3 / 6371), 'longitude' => 0];
        $local = (new CustomerDeliveryQuote)->calculate($branch, $address, 225, [
            'delivery_pricing_method' => 'slabs',
            'delivery_charge_slabs' => [],
        ]);
        $provider = Mockery::mock(DeliveryProvider::class);
        $provider->shouldReceive('quotes')->once()->andReturn([
            new DeliveryQuote('network', 'Delivery partner', 76.70, 25, 'quote-1'),
        ]);

        $quote = (new ThirdPartyCustomerPricing($provider, new DeliveryCommercialTerms))
            ->apply($branch, $address, $local, 'CHECKOUT-1', true);

        $this->assertTrue($quote['serviceable']);
        $this->assertSame(77.70, $quote['delivery_fee']);
        $this->assertSame(76.70, $quote['provider_cost']);
        $this->assertSame(1.0, $quote['platform_fee']);
        $this->assertSame('provider_quote_plus_platform_fee', $quote['pricing_rule']);
        $this->assertFalse($quote['free_delivery']);
    }

    public function test_corrupt_slabs_never_fall_back_to_provider_pricing(): void
    {
        $this->fakeSettings(['third_party_delivery_enabled' => true, 'delivery_platform_fee_per_order' => 1]);
        $branch = new Branch(['latitude' => 0, 'longitude' => 0, 'delivery_radius_km' => 8]);
        $address = ['latitude' => rad2deg(3 / 6371), 'longitude' => 0];
        $local = (new CustomerDeliveryQuote)->calculate($branch, $address, 225, [
            'delivery_pricing_method' => 'slabs',
            'delivery_charge_slabs' => [['min_km' => 2, 'max_km' => 5, 'charge' => 20]],
        ]);
        $provider = Mockery::mock(DeliveryProvider::class);
        $provider->shouldNotReceive('quotes');

        $quote = (new ThirdPartyCustomerPricing($provider, new DeliveryCommercialTerms))
            ->apply($branch, $address, $local, 'CHECKOUT-2', true);

        $this->assertFalse($quote['serviceable']);
        $this->assertNull($quote['delivery_fee']);
    }

    private function fakeSettings(array $values): void
    {
        app()->instance('setting', new class($values) {
            public function __construct(private readonly array $values) {}
            public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
        });
    }
}
