<?php

namespace Modules\Order\Delivery;

final class DeliveryCostCalculator
{
    /** @return array{restaurant_contribution: float, platform_contribution: float, delivery_margin: float} */
    public function split(float $customerFee, float $providerCost, float $platformContribution = 0): array
    {
        $shortfall = max(0, $providerCost - $customerFee);
        $platform = min($shortfall, max(0, $platformContribution));

        return [
            'restaurant_contribution' => round($shortfall - $platform, 2),
            'platform_contribution' => round($platform, 2),
            'delivery_margin' => round(max(0, $customerFee - $providerCost), 2),
        ];
    }
}
