<?php

namespace Tests\Unit\Order;

use Modules\Order\Delivery\DeliveryMoney;
use Tests\TestCase;

class DeliveryMoneyTest extends TestCase
{
    public function test_decimal_rounding_and_addition_follow_currency_precision(): void
    {
        $this->assertSame(2013, DeliveryMoney::minor('20.125', 'INR'));
        $this->assertSame(20, DeliveryMoney::minor('20.125', 'JPY'));
        $this->assertSame(20125, DeliveryMoney::minor('20.125', 'KWD'));
        $this->assertSame(120.13, DeliveryMoney::add('100', '20.125', 'INR'));
        $this->assertSame(120.125, DeliveryMoney::add('100', '20.125', 'KWD'));
        $this->assertSame(0.3, DeliveryMoney::add('0.1', '0.2', 'INR'));
        $this->assertSame(-2013, DeliveryMoney::minor('-20.125', 'INR'));
    }

    public function test_stale_totals_use_the_smallest_currency_unit_not_fixed_two_decimal_tolerance(): void
    {
        $this->assertNotSame(DeliveryMoney::minor('120.125', 'KWD'), DeliveryMoney::minor('120.124', 'KWD'));
        $this->assertSame(DeliveryMoney::minor('120.13000', 'INR'), DeliveryMoney::minor('120.13', 'INR'));
    }
}
