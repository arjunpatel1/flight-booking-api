<?php

namespace Tests\Unit\Order;

use Modules\Order\Support\StaffOrderAlertFormatter;
use PHPUnit\Framework\TestCase;

final class StaffOrderAlertFormatterTest extends TestCase
{
    public function test_it_formats_a_clear_nexmsg_safe_staff_order_summary(): void
    {
        $message = (new StaffOrderAlertFormatter)->format(
            'Swiggy Food on Train Pre-Order',
            [['name' => 'Paneer Butter Masala', 'quantity' => 1]],
            null,
            null,
            'Asia/Kolkata',
        );

        self::assertSame('Swiggy Food on Train Pre-Order • Items: Paneer Butter Masala × 1', $message);
        self::assertStringNotContainsString("\n", $message);
        self::assertStringNotContainsString("\t", $message);
    }

    public function test_it_normalises_decimal_quantities_and_long_lists(): void
    {
        $items = array_map(fn (int $index) => ['name' => "Item {$index}", 'quantity' => $index === 1 ? 1.5 : 1], range(1, 10));
        $message = (new StaffOrderAlertFormatter)->format('Customer Web', $items, null, null, 'Asia/Kolkata');

        self::assertStringContainsString('Item 1 × 1.5', $message);
        self::assertStringContainsString('+2 more items', $message);
        self::assertStringNotContainsString('Delivery slot:', $message);
        self::assertStringNotContainsString('Instructions:', $message);
    }
}
