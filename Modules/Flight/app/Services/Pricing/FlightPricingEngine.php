<?php

namespace Modules\Flight\Services\Pricing;

use Modules\Flight\Models\FlightFare;

class FlightPricingEngine
{
    /**
     * Compute full pricing breakdown along the specified pipeline:
     *
     * Supplier Fare
     *      ↓
     * Pricing Rules
     *      ↓
     * Platform Markup
     *      ↓
     * Tenant Markup
     *      ↓
     * Client Markup
     *      ↓
     * Convenience Fee
     *      ↓
     * GST
     *      ↓
     * Discount
     *      ↓
     * Final Customer Price
     *
     * @param array $input
     * @return array
     */
    public function calculate(array $input): array
    {
        $supplierFare = max(0, (float) ($input['supplier_fare'] ?? 0));
        $taxes = max(0, (float) ($input['taxes'] ?? 0));
        $supplierFees = max(0, (float) ($input['supplier_fees'] ?? 0));

        // 1. Pricing Rules Adjustment
        $pricingRulesAdjustment = (float) ($input['pricing_rules_adjustment'] ?? 0);
        $baseFare = max(0, $supplierFare + $pricingRulesAdjustment);

        // 2. Platform Markup (Fixed or Percentage)
        $platformMarkup = $this->calculateMarkup(
            $baseFare,
            $input['platform_markup'] ?? ($input['platform_markup_value'] ?? 0),
            $input['platform_markup_type'] ?? 'fixed'
        );

        // 3. Tenant Markup
        $tenantMarkup = $this->calculateMarkup(
            $baseFare,
            $input['tenant_markup'] ?? ($input['tenant_markup_value'] ?? 0),
            $input['tenant_markup_type'] ?? 'fixed'
        );

        // 4. Client Markup
        $clientMarkup = $this->calculateMarkup(
            $baseFare,
            $input['client_markup'] ?? ($input['client_markup_value'] ?? 0),
            $input['client_markup_type'] ?? 'fixed'
        );

        // 5. Convenience Fee
        $convenienceFee = max(0, (float) ($input['convenience_fee'] ?? 0));

        // 6. GST Calculation
        // By default GST applies to markups and convenience fee (service components),
        // or a direct rate/amount can be provided.
        $gstRate = isset($input['gst_rate']) ? (float) $input['gst_rate'] : null;
        if ($gstRate !== null) {
            $taxableServices = $platformMarkup + $tenantMarkup + $clientMarkup + $convenienceFee;
            $gst = round($taxableServices * ($gstRate / 100), 2);
        } else {
            $gst = max(0, (float) ($input['gst'] ?? 0));
        }

        // 7. Discount
        $discount = max(0, (float) ($input['discount'] ?? 0));

        // 8. Final Customer Price
        $grossTotal = $baseFare + $taxes + $supplierFees + $platformMarkup + $tenantMarkup + $clientMarkup + $convenienceFee + $gst;
        $finalCustomerPrice = max(0, round($grossTotal - $discount, 2));

        return [
            'supplier_fare' => round($supplierFare, 2),
            'pricing_rules_adjustment' => round($pricingRulesAdjustment, 2),
            'base_fare' => round($baseFare, 2),
            'taxes' => round($taxes, 2),
            'supplier_fees' => round($supplierFees, 2),
            'platform_markup' => round($platformMarkup, 2),
            'tenant_markup' => round($tenantMarkup, 2),
            'client_markup' => round($clientMarkup, 2),
            'convenience_fee' => round($convenienceFee, 2),
            'gst' => round($gst, 2),
            'discount' => round($discount, 2),
            'final_customer_price' => round($finalCustomerPrice, 2),
            'pipeline_steps' => [
                ['step' => 1, 'name' => 'Supplier Fare', 'amount' => round($supplierFare, 2)],
                ['step' => 2, 'name' => 'Pricing Rules Adjustment', 'amount' => round($pricingRulesAdjustment, 2), 'running_base' => round($baseFare, 2)],
                ['step' => 3, 'name' => 'Platform Markup', 'amount' => round($platformMarkup, 2)],
                ['step' => 4, 'name' => 'Tenant Markup', 'amount' => round($tenantMarkup, 2)],
                ['step' => 5, 'name' => 'Client Markup', 'amount' => round($clientMarkup, 2)],
                ['step' => 6, 'name' => 'Convenience Fee', 'amount' => round($convenienceFee, 2)],
                ['step' => 7, 'name' => 'GST', 'amount' => round($gst, 2)],
                ['step' => 8, 'name' => 'Discount', 'amount' => -round($discount, 2)],
                ['step' => 9, 'name' => 'Final Customer Price', 'amount' => round($finalCustomerPrice, 2)],
            ],
        ];
    }

    /**
     * Calculate markup based on mode.
     */
    protected function calculateMarkup(float $base, float $value, string $type = 'fixed'): float
    {
        if ($value <= 0) {
            return 0.0;
        }

        if (in_array(strtolower($type), ['percentage', 'percent', '%'])) {
            return round($base * ($value / 100), 2);
        }

        return round($value, 2);
    }
}
