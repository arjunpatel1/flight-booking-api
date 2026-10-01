<?php

namespace Tests\Unit\Aggregator;

use Modules\Aggregator\Enums\AggregatorProvider;
use Modules\Aggregator\Models\AggregatorIntegration;
use Modules\Aggregator\Services\Providers\AggregatorProviderFactory;
use Modules\Aggregator\Services\Providers\ContractPendingProvider;
use Modules\Aggregator\Services\Providers\SwiggyProvider;
use Modules\Aggregator\Services\Providers\ZomatoProvider;
use Tests\TestCase;

class AggregatorProviderFactoryTest extends TestCase
{
    public function test_factory_resolves_swiggy_and_zomato_adapters(): void
    {
        $factory = app(AggregatorProviderFactory::class);

        $swiggy = new AggregatorIntegration([
            'provider' => AggregatorProvider::Swiggy,
        ]);

        $zomato = new AggregatorIntegration([
            'provider' => AggregatorProvider::Zomato,
        ]);

        $this->assertInstanceOf(SwiggyProvider::class, $factory->make($swiggy));
        $this->assertInstanceOf(ZomatoProvider::class, $factory->make($zomato));
    }

    public function test_factory_resolves_future_marketplace_adapters(): void
    {
        $factory = app(AggregatorProviderFactory::class);

        foreach ([AggregatorProvider::Ondc, AggregatorProvider::Magicpin, AggregatorProvider::Dunzo, AggregatorProvider::Porter] as $provider) {
            $integration = new AggregatorIntegration(['provider' => $provider]);

            $this->assertInstanceOf(ContractPendingProvider::class, $factory->make($integration));
        }
    }

    public function test_provider_capabilities_are_explicit(): void
    {
        $capabilities = app(AggregatorProviderFactory::class)
            ->make(new AggregatorIntegration(['provider' => AggregatorProvider::Swiggy]))
            ->capabilities();

        $this->assertFalse($capabilities['supports_menu_sync']);
        $this->assertFalse($capabilities['supports_status_push']);
        $this->assertFalse($capabilities['supports_inventory_sync']);
        $this->assertArrayHasKey('supports_refunds', $capabilities);
        $this->assertArrayHasKey('supports_delivery_tracking', $capabilities);
    }

    public function test_zomato_provider_exposes_official_public_status_capabilities(): void
    {
        $capabilities = app(AggregatorProviderFactory::class)
            ->make(new AggregatorIntegration(['provider' => AggregatorProvider::Zomato]))
            ->capabilities();

        $this->assertTrue($capabilities['supports_status_push']);
        $this->assertTrue($capabilities['supports_delivery_tracking']);
        $this->assertFalse($capabilities['supports_menu_sync']);
    }

}
