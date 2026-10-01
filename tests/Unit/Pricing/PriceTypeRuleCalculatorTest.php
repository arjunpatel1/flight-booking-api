<?php

namespace Tests\Unit\Pricing;

use Modules\Pricing\Enums\PriceTypeRuleType;
use Modules\Pricing\Support\PriceTypeRuleCalculator;
use PHPUnit\Framework\TestCase;

class PriceTypeRuleCalculatorTest extends TestCase
{
    public function test_flat_rule_adds_amount_to_base_price(): void
    {
        $this->assertSame(120.0, PriceTypeRuleCalculator::resolve(100, PriceTypeRuleType::Flat, 20, 'AC'));
    }

    public function test_percent_rule_adds_percent_to_base_price(): void
    {
        $this->assertSame(110.0, PriceTypeRuleCalculator::resolve(100, PriceTypeRuleType::Percent, 10, 'VIP'));
    }

    public function test_fixed_rule_replaces_base_price_when_value_is_positive(): void
    {
        $this->assertSame(500.0, PriceTypeRuleCalculator::resolve(100, PriceTypeRuleType::Fixed, 500, 'VVIP'));
    }

    public function test_zero_self_service_rule_keeps_base_price(): void
    {
        $this->assertSame(100.0, PriceTypeRuleCalculator::resolve(100, PriceTypeRuleType::Fixed, 0, 'SELF_SERVICE'));
    }

    public function test_negative_result_is_never_returned(): void
    {
        $this->assertSame(0.0, PriceTypeRuleCalculator::resolve(-100, PriceTypeRuleType::Flat, -20, 'AC'));
    }
}
