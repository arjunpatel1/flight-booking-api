<?php

namespace Modules\Order\Delivery;

use Modules\Branch\Models\Branch;

final class ThirdPartyCustomerPricing
{
    public function __construct(
        private readonly DeliveryProvider $provider,
        private readonly DeliveryCommercialTerms $terms,
    ) {}

    public function apply(Branch $branch, array $address, array $localQuote, string $reference, bool $cod): array
    {
        $providerFallback = ! $localQuote['serviceable']
            && ($localQuote['failure_code'] ?? null) === 'DELIVERY_PRICE_UNAVAILABLE'
            && ($localQuote['provider_pricing_fallback_allowed'] ?? false) === true;
        if ((! $localQuote['serviceable'] && ! $providerFallback)
            || ! (bool) setting('third_party_delivery_enabled', false)) {
            return $localQuote;
        }

        $pickup = DeliveryLocation::fromAddress(['latitude' => $branch->latitude, 'longitude' => $branch->longitude]);
        $dropoff = DeliveryLocation::fromAddress($address);
        if (! $pickup || ! $dropoff) {
            return [...$localQuote, 'serviceable' => false, 'delivery_fee' => null,
                'failure_code' => 'DELIVERY_LOCATION_MISSING',
                'message' => 'Home delivery is not available at this location.'];
        }

        $providerQuote = collect($this->provider->quotes($pickup, $dropoff, $reference, $cod))
            ->filter(fn (DeliveryQuote $quote) => $quote->serviceable)
            ->sortBy('cost')->first();
        if (! $providerQuote) {
            return [...$localQuote, 'serviceable' => false, 'delivery_fee' => null,
                'failure_code' => 'OUTSIDE_PROVIDER_COVERAGE',
                'message' => 'Home delivery is not available at this location.'];
        }

        if ($providerFallback) {
            $customerFee = $this->terms->customerFee($providerQuote->cost);

            return [...$localQuote,
                'serviceable' => true,
                'base_delivery_fee' => $customerFee,
                'delivery_fee_before_gst' => $customerFee,
                'delivery_fee_gst_rate' => 0.0,
                'delivery_fee_gst' => 0.0,
                'delivery_fee' => $customerFee,
                'free_delivery' => false,
                'matched_free_delivery_rule' => null,
                'pricing_rule' => 'provider_quote_plus_platform_fee',
                'failure_code' => null,
                'message' => null,
                'provider_cost' => round($providerQuote->cost, 2),
                'platform_fee' => $this->terms->platformFeePerOrder(),
            ];
        }

        // Configured restaurant pricing remains authoritative. Provider cost
        // is returned for booking and settlement, but is not exposed as a
        // separately identifiable provider charge to the customer.
        return [...$localQuote,
            'provider_cost' => round($providerQuote->cost, 2),
            'platform_fee' => $this->terms->platformFeePerOrder()];
    }
}
