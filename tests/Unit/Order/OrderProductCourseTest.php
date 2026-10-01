<?php

namespace Tests\Unit\Order;

use Modules\Order\Models\OrderProduct;
use Tests\TestCase;

/**
 * Pure unit coverage for the course "held" rule. No database.
 */
class OrderProductCourseTest extends TestCase
{
    public function test_uncoursed_item_is_never_held(): void
    {
        $product = new OrderProduct();
        $product->course_number = null;
        $product->fired_at = null;

        $this->assertFalse($product->isHeld());
    }

    public function test_coursed_unfired_item_is_held(): void
    {
        $product = new OrderProduct();
        $product->course_number = 2;
        $product->fired_at = null;

        $this->assertTrue($product->isHeld());
    }

    public function test_coursed_fired_item_is_not_held(): void
    {
        $product = new OrderProduct();
        $product->course_number = 2;
        $product->fired_at = now();

        $this->assertFalse($product->isHeld());
    }
}
