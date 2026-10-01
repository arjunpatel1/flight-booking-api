<?php

namespace Tests\Feature\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Order\Delivery\DeliveryPaymentPolicy;
use Modules\Setting\Enums\SettingSection;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use Tests\TestCase;

/**
 * Checkout must only offer delivery payment types the delivery partner will
 * accept, and the Delivery page's cash-on-delivery switch must be the same
 * switch checkout uses.
 */
class DeliveryPaymentPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_restaurant_delivery_accepts_online_payment_without_partner_switches(): void
    {
        setting(['third_party_delivery_enabled' => false, 'delivery_prepaid_enabled' => false]);

        $this->assertTrue(DeliveryPaymentPolicy::allowsOnlinePayment());
    }

    public function test_third_party_delivery_requires_the_prepaid_switch_for_online_payment(): void
    {
        setting(['third_party_delivery_enabled' => true, 'delivery_prepaid_enabled' => false]);
        $this->assertFalse(DeliveryPaymentPolicy::allowsOnlinePayment());

        setting(['delivery_prepaid_enabled' => true]);
        $this->assertTrue(DeliveryPaymentPolicy::allowsOnlinePayment());
    }

    public function test_cash_on_delivery_needs_checkout_and_partner_permission(): void
    {
        setting(['third_party_delivery_enabled' => true, 'customer_payment_cod_enabled' => true, 'delivery_cod_enabled' => false]);
        $this->assertFalse(DeliveryPaymentPolicy::allowsCashOnDelivery());

        setting(['delivery_cod_enabled' => true]);
        $this->assertTrue(DeliveryPaymentPolicy::allowsCashOnDelivery());

        setting(['customer_payment_cod_enabled' => false]);
        $this->assertFalse(DeliveryPaymentPolicy::allowsCashOnDelivery());
    }

    public function test_saving_delivery_settings_keeps_checkout_cash_on_delivery_in_step(): void
    {
        setting(['customer_payment_cod_enabled' => false]);
        $service = app(SettingServiceInterface::class);

        $service->update(SettingSection::Delivery, ['delivery_cod_enabled' => true]);
        $this->assertTrue((bool) setting('customer_payment_cod_enabled'));

        $service->update(SettingSection::Delivery, ['delivery_cod_enabled' => false]);
        $this->assertFalse((bool) setting('customer_payment_cod_enabled'));
    }
}
