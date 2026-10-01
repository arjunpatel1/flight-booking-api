<?php

namespace Tests\Unit\Order;

use Modules\Order\Models\Order;
use Modules\Order\Support\OnlineOrderDetails;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OnlineOrderDetailsTest extends TestCase
{
    public static function checkoutCases(): array
    {
        $address = ['address_line1' => '12 Main Street', 'landmark' => 'Near Market', 'city' => 'Dewas', 'phone' => '+910000000000'];
        return [
            'notes only' => ['delivery', 'Less spicy', [], 'Less spicy', []],
            'address only' => ['delivery', '', $address, null, $address],
            'address and notes' => ['delivery', 'Less spicy, no onion', $address, 'Less spicy, no onion', $address],
            'empty' => ['delivery', '', [], null, []],
            'pickup' => ['pick_up', 'Pack separately', $address, 'Pack separately', null],
            'takeaway' => ['takeaway', '', $address, null, null],
            'dine in' => ['dine_in', 'No onion', $address, 'No onion', null],
        ];
    }

    #[DataProvider('checkoutCases')]
    public function test_checkout_keeps_fulfilment_out_of_kitchen_notes(string $type, string $notes, array $address, ?string $expectedNotes, ?array $expectedAddress): void
    {
        $details = OnlineOrderDetails::fromCheckout([
            'type' => $type, 'notes' => $notes, 'delivery_address' => $address,
            'customer_mobile' => '+910000000000', 'payment_method' => 'pay_at_counter',
        ]);
        $order = new Order($details);
        // Both the kitchen resource and KOT factory read this same model field.
        $this->assertSame($expectedNotes, $order->notes);
        $this->assertSame($expectedAddress, $order->fulfilmentDetails()['delivery_address'] ?? null);
        $this->assertStringNotContainsString('Main Street', (string) $order->notes);
        $this->assertStringNotContainsString('+910000000000', (string) $order->notes);
        $this->assertSame('pay_at_counter', $order->fulfilmentDetails()['payment_preference']);
    }

    public function test_legacy_notes_are_clean_for_kitchen_and_address_is_preserved(): void
    {
        $order = new Order(['notes' => "Less spicy, no onion\nRoom / Table: 12\nCustomer: Test Diner\nCustomer mobile: +910000000000\nPAYMENT PREFERENCE: CASH_ON_DELIVERY\nDELIVERY ADDRESS: 12 Main Street, Near Market, Dewas"]);
        $this->assertSame('Less spicy, no onion', $order->notes);
        $this->assertSame('12 Main Street, Near Market, Dewas', $order->fulfilmentDetails()['delivery_summary']);
        $this->assertSame('12', $order->fulfilmentDetails()['room_number']);
        // Reading must not rewrite stored legacy data or run a migration.
        $this->assertStringContainsString('DELIVERY ADDRESS', $order->getAttributes()['notes']);
    }

    public function test_saving_legacy_cooking_notes_retains_the_address_snapshot(): void
    {
        $order = new Order(['notes' => "No onion\nPAYMENT PREFERENCE: CASH\nDELIVERY ADDRESS: 12 Main Street"]);
        $order->fulfilment = $order->fulfilmentDetails();
        $order->notes = 'Extra crispy';
        $this->assertSame('Extra crispy', $order->notes);
        $this->assertSame('12 Main Street', $order->fulfilmentDetails()['delivery_summary']);
    }

    public function test_unrelated_staff_notes_are_not_reinterpreted(): void
    {
        $notes = "Pack separately\nCustomer: prefers no cutlery";
        $this->assertSame($notes, (new Order(['notes' => $notes]))->notes);
        $this->assertSame($notes, (new Order(['notes' => $notes, 'fulfilment' => []]))->notes);
    }
}
