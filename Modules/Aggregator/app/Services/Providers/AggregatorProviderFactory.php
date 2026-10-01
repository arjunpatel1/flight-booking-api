<?php

namespace Modules\Aggregator\Services\Providers;

use Modules\Aggregator\Enums\AggregatorProvider;
use Modules\Aggregator\Models\AggregatorIntegration;

class AggregatorProviderFactory
{
    public function make(AggregatorIntegration $integration): AggregatorProviderInterface
    {
        return match ($integration->provider) {
            AggregatorProvider::Swiggy => app(SwiggyProvider::class),
            AggregatorProvider::Zomato => app(ZomatoProvider::class),
            AggregatorProvider::Ondc => new ContractPendingProvider('ONDC'),
            AggregatorProvider::Magicpin => new ContractPendingProvider('Magicpin'),
            AggregatorProvider::Dunzo => new ContractPendingProvider('Dunzo'),
            AggregatorProvider::Porter => new ContractPendingProvider('Porter'),
            AggregatorProvider::Blinkit => new ContractPendingProvider('Blinkit'),
            AggregatorProvider::UberEats => new ContractPendingProvider('Uber Eats'),
        };
    }
}
