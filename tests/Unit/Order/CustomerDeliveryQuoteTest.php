<?php

namespace Tests\Unit\Order;

use Modules\Branch\Models\Branch;
use Modules\Order\Delivery\CustomerDeliveryQuote;
use Tests\TestCase;

class CustomerDeliveryQuoteTest extends TestCase
{
    private function quote(float $distance, array $overrides = [], float $subtotal = 100): array
    {
        $branch = new Branch(['latitude' => 0, 'longitude' => 0, 'delivery_radius_km' => 8]);

        return (new CustomerDeliveryQuote)->calculate($branch,
            ['latitude' => rad2deg($distance / 6371), 'longitude' => 0], $subtotal,
            [...['delivery_pricing_method' => 'slabs', 'delivery_charge_slabs' => [
                ['min_km' => 0, 'max_km' => 2, 'charge' => 20],
                ['min_km' => 2, 'max_km' => 5, 'charge' => 35],
                ['min_km' => 5, 'max_km' => 8, 'charge' => 50],
            ]], ...$overrides]);
    }

    public function test_slabs_have_deterministic_boundaries(): void
    {
        foreach ([0 => 20, 2 => 20, 5 => 35, 8 => 50] as $distance => $fee) {
            $quote = $this->quote($distance);
            $this->assertTrue($quote['serviceable']);
            $this->assertSame((float) $fee, $quote['delivery_fee']);
        }
        $this->assertSame(35.0, $this->quote(2.001)['delivery_fee']);
        $this->assertSame(50.0, $this->quote(5.001)['delivery_fee']);
        $this->assertFalse($this->quote(8.001)['serviceable']);
    }

    public function test_missing_locations_and_outlet_radius_are_enforced(): void
    {
        $calculator = new CustomerDeliveryQuote;
        $this->assertSame('RESTAURANT_LOCATION_MISSING', $calculator->calculate(new Branch, [], 100, [])['failure_code']);
        $this->assertSame('CUSTOMER_LOCATION_MISSING', $calculator->calculate(new Branch(['latitude' => 0, 'longitude' => 0]), [], 100, [])['failure_code']);
        $this->assertFalse($this->quote(4, ['maximum_delivery_radius_km' => 3])['serviceable']);
        $this->assertFalse($this->quote(9, ['maximum_delivery_radius_km' => 20])['serviceable']);
    }

    public function test_free_delivery_and_base_plus_started_kilometre(): void
    {
        $this->assertSame(0.0, $this->quote(3, ['free_delivery_above_order_amount' => 500], 500)['delivery_fee']);
        $this->assertSame(35.0, $this->quote(3, ['free_delivery_above_order_amount' => 500], 499.99)['delivery_fee']);
        $this->assertSame(30.0, $this->quote(2.1, ['delivery_pricing_method' => 'base_plus_km',
            'base_delivery_charge' => 20, 'base_distance_km' => 2, 'per_additional_km_charge' => 10])['delivery_fee']);
    }

    public function test_base_plus_started_kilometre_adds_customer_delivery_gst(): void
    {
        $quote = $this->quote(7.2, [
            'delivery_pricing_method' => 'base_plus_km',
            'base_delivery_charge' => 66,
            'base_distance_km' => 3,
            'per_additional_km_charge' => 12,
            'delivery_customer_fee_gst_enabled' => true,
            'delivery_customer_fee_gst_rate' => 18,
        ]);

        $this->assertSame(126.0, $quote['delivery_fee_before_gst']);
        $this->assertSame(22.68, $quote['delivery_fee_gst']);
        $this->assertSame(148.68, $quote['delivery_fee']);
        $this->assertSame('base_plus_started_km', $quote['pricing_rule']);
    }

    public function test_customer_delivery_gst_is_off_by_default(): void
    {
        $quote = $this->quote(1, ['delivery_customer_fee_gst_rate' => 18]);

        $this->assertSame(20.0, $quote['delivery_fee_before_gst']);
        $this->assertSame(0.0, $quote['delivery_fee_gst']);
        $this->assertSame(0.0, $quote['delivery_fee_gst_rate']);
        $this->assertSame(20.0, $quote['delivery_fee']);
    }

    public function test_free_delivery_rule_requires_both_order_and_distance(): void
    {
        $rules = ['delivery_free_rules' => [[
            'name' => 'FREE DELIVERY 500', 'minimum_order_amount' => 500,
            'maximum_distance_km' => 5, 'active' => true,
        ]]];

        $this->assertTrue($this->quote(5, $rules, 500)['free_delivery']);
        $this->assertFalse($this->quote(5.001, $rules, 500)['free_delivery']);
        $this->assertFalse($this->quote(5, $rules, 499.99)['free_delivery']);
        $this->assertSame('FREE DELIVERY 500', $this->quote(5, $rules, 500)['matched_free_delivery_rule']);
    }

    public function test_empty_pricing_slabs_fail_closed_instead_of_inventing_free_delivery(): void
    {
        $quote = $this->quote(3, ['delivery_charge_slabs' => []]);
        $this->assertFalse($quote['serviceable']);
        $this->assertNull($quote['delivery_fee']);
        $this->assertFalse($quote['free_delivery']);
        $this->assertNull($quote['pricing_rule']);
        $this->assertSame('DELIVERY_PRICE_UNAVAILABLE', $quote['failure_code']);
        $this->assertStringContainsString('not been configured', $quote['message']);
    }

    public function test_corrupt_slabs_fail_closed_and_exact_base_boundary_is_stable(): void
    {
        foreach ([1, 3] as $min) {
            $this->assertFalse($this->quote(3, ['delivery_charge_slabs' => [
                ['min_km' => 0, 'max_km' => 2, 'charge' => 20],
                ['min_km' => $min, 'max_km' => 8, 'charge' => 35],
            ]])['serviceable']);
        }
        $this->assertSame(20.0, $this->quote(2, ['delivery_pricing_method' => 'base_plus_km',
            'base_delivery_charge' => 20, 'base_distance_km' => 2, 'per_additional_km_charge' => 10])['delivery_fee']);
    }
}
