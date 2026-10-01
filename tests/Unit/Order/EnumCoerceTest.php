<?php

namespace Tests\Unit\Order;

use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Tests\TestCase;

/**
 * Regression test for the TypeError that crashed GET /pos/waiter-dashboard.
 *
 * Eloquent's enum casts hydrate model attributes as enum instances.  Any code
 * that then calls EnumClass::from($model->attribute) passes an enum object
 * where a string|int is expected, causing a fatal TypeError.
 *
 * The coerce() helper resolves all three input shapes correctly.
 */
class EnumCoerceTest extends TestCase
{
    // ─── OrderType ───────────────────────────────────────────────────────────

    public function test_order_type_coerce_from_string(): void
    {
        $this->assertSame(OrderType::DineIn, OrderType::coerce('dine_in'));
        $this->assertSame(OrderType::Takeaway, OrderType::coerce('takeaway'));
        $this->assertSame(OrderType::Delivery, OrderType::coerce('delivery'));
    }

    public function test_order_type_coerce_from_enum_instance_returns_same_instance(): void
    {
        $instance = OrderType::DineIn;
        $this->assertSame($instance, OrderType::coerce($instance));
    }

    public function test_order_type_coerce_survives_eloquent_cast_double_wrap(): void
    {
        // Simulate Eloquent returning an already-cast enum attribute and the
        // service code calling ::from() on it — this was the crash scenario.
        $castByEloquent = OrderType::Takeaway;

        // Must NOT throw TypeError
        $result = OrderType::coerce($castByEloquent);
        $this->assertSame(OrderType::Takeaway, $result);
    }

    // ─── OrderStatus ─────────────────────────────────────────────────────────

    public function test_order_status_coerce_from_string(): void
    {
        $this->assertSame(OrderStatus::Pending, OrderStatus::coerce('pending'));
        $this->assertSame(OrderStatus::Completed, OrderStatus::coerce('completed'));
        $this->assertSame(OrderStatus::Cancelled, OrderStatus::coerce('cancelled'));
    }

    public function test_order_status_coerce_from_enum_instance(): void
    {
        $instance = OrderStatus::Preparing;
        $this->assertSame($instance, OrderStatus::coerce($instance));
    }

    // ─── OrderPaymentStatus ──────────────────────────────────────────────────

    public function test_order_payment_status_coerce_from_string(): void
    {
        $this->assertSame(OrderPaymentStatus::Paid, OrderPaymentStatus::coerce('paid'));
        $this->assertSame(OrderPaymentStatus::Unpaid, OrderPaymentStatus::coerce('unpaid'));
        $this->assertSame(OrderPaymentStatus::PartiallyPaid, OrderPaymentStatus::coerce('partially_paid'));
    }

    public function test_order_payment_status_coerce_from_enum_instance(): void
    {
        $instance = OrderPaymentStatus::PartiallyPaid;
        $this->assertSame($instance, OrderPaymentStatus::coerce($instance));
    }

    // ─── Dashboard payload simulation ────────────────────────────────────────

    public function test_waiter_dashboard_order_map_does_not_throw_with_cast_enums(): void
    {
        // Simulate the three attributes as Eloquent would return them after casting.
        $castType          = OrderType::DineIn;
        $castStatus        = OrderStatus::Confirmed;
        $castPaymentStatus = OrderPaymentStatus::Unpaid;

        // Before the fix this block would throw:
        // "Argument #1 must be of type string|int, OrderType given"
        $type          = OrderType::coerce($castType);
        $status        = OrderStatus::coerce($castStatus);
        $paymentStatus = OrderPaymentStatus::coerce($castPaymentStatus);

        $this->assertSame(OrderType::DineIn, $type);
        $this->assertSame(OrderStatus::Confirmed, $status);
        $this->assertSame(OrderPaymentStatus::Unpaid, $paymentStatus);

        // Verify backing values are intact (no DB needed).
        $this->assertSame('dine_in', $type->value);
        $this->assertSame('confirmed', $status->value);
        $this->assertSame('unpaid', $paymentStatus->value);
    }
}
