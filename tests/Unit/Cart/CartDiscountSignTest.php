<?php

namespace Tests\Unit\Cart;

use Modules\Cart\CartDiscount;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class CartDiscountSignTest extends TestCase
{
    #[Test]
    public function a_negative_cart_condition_becomes_a_positive_amount_to_subtract(): void
    {
        $method = new ReflectionMethod(CartDiscount::class, 'positiveAmount');

        $this->assertSame(15.0, $method->invoke(null, -15.0));
        $this->assertSame(15.0, $method->invoke(null, 15.0));
    }
}
