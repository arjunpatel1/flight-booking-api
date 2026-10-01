<?php

namespace Tests\Unit\Order;

use Carbon\CarbonImmutable;
use Modules\Branch\Models\Branch;
use Modules\Order\Delivery\DeliveryAvailability;
use Tests\TestCase;

class DeliveryAvailabilityTest extends TestCase
{
    public function test_restaurant_switch_and_legacy_unscheduled_hours(): void
    {
        $branch = new Branch(['timezone' => 'Asia/Kolkata']);
        $policy = ['delivery_ordering_enabled' => true, 'delivery_schedule_enabled' => false];
        $availability = new DeliveryAvailability;

        $this->assertTrue($availability->status($branch, CarbonImmutable::parse('2026-09-21 03:00:00', 'Asia/Kolkata'), $policy)['available']);
        $this->assertSame('DELIVERY_DISABLED', $availability->status($branch, null,
            ['delivery_ordering_enabled' => false, 'delivery_schedule_enabled' => false])['code']);
    }

    public function test_weekly_hours_follow_outlet_timezone_and_exclude_closing_minute(): void
    {
        $branch = new Branch(['timezone' => 'Asia/Kolkata']);
        $policy = ['delivery_ordering_enabled' => true, 'delivery_schedule_enabled' => true,
            'delivery_hours' => ['mon' => ['open' => '10:00', 'close' => '22:00']]];
        $availability = new DeliveryAvailability;
        $at = fn (string $time) => CarbonImmutable::parse('2026-09-21 '.$time, 'Asia/Kolkata');

        $this->assertFalse($availability->status($branch, $at('09:59'), $policy)['available']);
        $this->assertTrue($availability->status($branch, $at('10:00'), $policy)['available']);
        $this->assertTrue($availability->status($branch, $at('21:59'), $policy)['available']);
        $this->assertFalse($availability->status($branch, $at('22:00'), $policy)['available']);
        $this->assertFalse($availability->status($branch, CarbonImmutable::parse('2026-09-22 12:00:00', 'Asia/Kolkata'), $policy)['available']);
    }

    public function test_overnight_hours_continue_into_next_day_and_bad_schedule_fails_closed(): void
    {
        $branch = new Branch(['timezone' => 'Asia/Kolkata']);
        $policy = ['delivery_ordering_enabled' => true, 'delivery_schedule_enabled' => true,
            'delivery_hours' => ['mon' => ['open' => '18:00', 'close' => '02:00']]];
        $availability = new DeliveryAvailability;

        $this->assertTrue($availability->status($branch, CarbonImmutable::parse('2026-09-22 01:59:00', 'Asia/Kolkata'), $policy)['available']);
        $this->assertFalse($availability->status($branch, CarbonImmutable::parse('2026-09-22 02:00:00', 'Asia/Kolkata'), $policy)['available']);
        $policy['delivery_hours'] = ['mon' => ['open' => 'invalid', 'close' => '02:00']];
        $this->assertSame('DELIVERY_OUTSIDE_HOURS', $availability->status($branch, null, $policy)['code']);
    }
}
