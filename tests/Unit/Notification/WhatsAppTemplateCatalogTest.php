<?php

namespace Tests\Unit\Notification;

use Modules\Notification\Services\WhatsApp\WhatsAppTemplateCatalog;
use PHPUnit\Framework\TestCase;

class WhatsAppTemplateCatalogTest extends TestCase
{
    public function test_catalog_contains_operational_templates_with_display_metadata(): void
    {
        $templates = collect(WhatsAppTemplateCatalog::defaults())->keyBy('event');

        foreach (['customer_otp', 'whatsapp_order_received_payment', 'whatsapp_order_received', 'order_submitted', 'order_accepted', 'preparing', 'ready', 'completed', 'cancelled', 'billing_sent', 'payment_received', 'payment_failed', 'refund_processed', 'table_booking_confirmed', 'table_booking_reminder', 'table_booking_cancelled', 'coupon', 'gift', 'reward', 'delivery_update'] as $event) {
            $this->assertTrue($templates->has($event), "Missing WhatsApp template event {$event}");
            $this->assertNotEmpty($templates[$event]['template_id']);
            $this->assertNotEmpty($templates[$event]['description']);
            $this->assertNotEmpty($templates[$event]['message']);
        }

        $this->assertStringContainsString('expires in 5 minutes', $templates['customer_otp']['message']);
        $this->assertStringStartsWith('*Order Received (Link Order)* ✅', $templates['order_submitted']['message']);
        $this->assertStringStartsWith('*Order Completed* ✅', $templates['completed']['message']);
        $this->assertStringStartsWith('*Order Cancelled* ❌', $templates['cancelled']['message']);
        $this->assertStringStartsWith('*Delivery Update* 🛵', $templates['delivery_update']['message']);
        $this->assertStringStartsWith('*New Restaurant Order* 🔔', collect(WhatsAppTemplateCatalog::defaults())->firstWhere('id', 'staff_order_received_professional')['message']);

        $deliveryFailure = collect(WhatsAppTemplateCatalog::defaults())->firstWhere('id', 'staff_delivery_not_created');
        $this->assertStringStartsWith('*Delivery Order Not Created* ⚠️', $deliveryFailure['message']);
        $this->assertSame('staff_delivery_not_created', $deliveryFailure['event']);
        $this->assertSame('nexdine_delivery_not_created_v2', $deliveryFailure['template_id']);
        $this->assertFalse($deliveryFailure['is_active']);
        $this->assertStringNotContainsString('uEngage', $deliveryFailure['message']);
        $this->assertStringStartsWith('*Rider Assignment Delayed* ⚠️', $templates['staff_delivery_rider_unassigned']['message']);
        $this->assertStringStartsWith('*Delivery Cancelled* ❌', $templates['staff_delivery_cancelled']['message']);

        $paymentReceipt = $templates['whatsapp_order_received_payment'];
        $this->assertSame(['Pay Now', 'Cancel Order'], collect($paymentReceipt['buttons'])->pluck('label')->all());
        $this->assertSame(['button_url_1'], array_slice($paymentReceipt['component_keys'], -1));
        $this->assertStringContainsString('30 min', $paymentReceipt['message']);

        $submitted = $templates['order_submitted'];
        $this->assertContains('tracking_link', $submitted['variables']);
        $this->assertSame('order_submitted', $submitted['template_id']);
        $this->assertSame(['Track Order'], collect($submitted['buttons'])->pluck('label')->all());

        $whatsAppReceipt = $templates['whatsapp_order_received'];
        $this->assertSame('nexdine_order_received_actions_v2', $whatsAppReceipt['template_id']);
        $this->assertSame(['tracking_link', 'cancel_token'], array_slice($whatsAppReceipt['variables'], -2));
        $this->assertSame(['Track Order', 'Cancel Order'], collect($whatsAppReceipt['buttons'])->pluck('label')->all());
        $this->assertSame('button_quick_reply_2', data_get($whatsAppReceipt, 'component_keys.5'));

        $staff = collect(WhatsAppTemplateCatalog::defaults())->firstWhere('id', 'staff_order_received_professional');
        $this->assertSame('nexdine_staff_order_details_v3', $staff['template_id']);
        $this->assertSame('Open Order', data_get($staff, 'buttons.0.label'));
        $this->assertStringNotContainsString('{{order_link}}', $staff['message']);
    }

    public function test_every_stock_template_uses_a_professional_heading(): void
    {
        $expected = [
            'staff_order_received' => '*New Order Received* 🍽️',
            'staff_order_received_link' => 'New Restaurant Order Received.',
            'staff_order_received_items' => '*New Order Received* 🔔',
            'staff_order_received_items_link' => '*New Order Received* 🔔',
            'staff_order_received_professional' => '*New Restaurant Order* 🔔',
            'staff_delivery_not_created' => '*Delivery Order Not Created* ⚠️',
            'staff_delivery_rider_unassigned' => '*Rider Assignment Delayed* ⚠️',
            'staff_delivery_cancelled' => '*Delivery Cancelled* ❌',
            'customer_login_otp' => '*OTP Verification* 🔐',
            'whatsapp_order_payment' => '*Order Received — Payment Required* 🧾',
            'whatsapp_order_received' => '*Order Received (WhatsApp Order)* ✅',
            'order_submitted' => '*Order Received (Link Order)* ✅',
            'order_accepted' => '*Order Confirmed* ✅',
            'order_preparing' => '*Preparing Order* 👨‍🍳',
            'order_ready' => '*Order Ready* 🛎️',
            'order_completed' => '*Order Completed* ✅',
            'order_cancelled' => '*Order Cancelled* ❌',
            'billing_sent' => '*Payment Received* ✅',
            'payment_received' => '*Payment Received* ✅',
            'payment_failed' => '*Payment Failed* ⚠️',
            'refund_processed' => '*Refund Processed* ✅',
            'table_booking_confirmed' => '*Reservation Confirmed* 🍽️',
            'table_booking_reminder' => '*Reservation Reminder* ⏰',
            'table_booking_cancelled' => '*Reservation Cancelled* ❌',
            'promotion_offer' => '*A Special Offer For You* 🎉',
            'coupon_sent' => '*Your Coupon Is Ready* 🎟️',
            'gift_added' => '*A Gift For You* 🎁',
            'loyalty_reward' => '*Loyalty Reward Unlocked* ⭐',
            'birthday_offer' => '*Happy Birthday, {{customer_name}}!* 🎂',
            'anniversary_offer' => '*Happy Anniversary, {{customer_name}}!* 💐',
            'inactive_customer_offer' => '*We Miss You, {{customer_name}}* 👋',
            'feedback_request' => '*How Was Your Order?*',
            'address_delivery_update' => '*Delivery Update* 🛵',
        ];
        $templates = collect(WhatsAppTemplateCatalog::defaults())->keyBy('id');

        $this->assertSame(array_keys($expected), $templates->keys()->all());
        foreach ($expected as $id => $heading) {
            $this->assertSame($heading, strtok($templates[$id]['message'], "\n"), "Unexpected heading for {$id}");
        }
    }

    public function test_merge_preserves_tenant_provider_id_and_adds_missing_defaults(): void
    {
        $merged = collect(WhatsAppTemplateCatalog::merge([[
            'id' => 'customer_login_otp',
            'template_id' => 'tenant_approved_otp_v2',
            'name' => 'My OTP',
            'is_active' => false,
        ]]))->keyBy('id');

        $this->assertSame('tenant_approved_otp_v2', $merged['customer_login_otp']['template_id']);
        $this->assertSame('My OTP', $merged['customer_login_otp']['name']);
        $this->assertFalse($merged['customer_login_otp']['is_active']);
        $this->assertNotEmpty($merged['customer_login_otp']['description']);
        $this->assertTrue($merged->has('billing_sent'));
    }

    public function test_merge_upgrades_only_the_legacy_completed_message_and_preserves_provider_identity(): void
    {
        $legacy = "*Order completed* ✅\n\nThank you, {{customer_name}}.\nOrder: *{{order_id}}*\nTotal paid: *{{order_total}}*\n\nWe would love your feedback:\n{{rating_link}}";
        $completed = collect(WhatsAppTemplateCatalog::merge([[
            'id' => 'order_completed', 'template_id' => 'tenant_completed_v2',
            'message' => $legacy, 'language_code' => 'en_US', 'is_active' => true,
        ]]))->firstWhere('id', 'order_completed');

        $this->assertSame('tenant_completed_v2', $completed['template_id']);
        $this->assertSame('en_US', $completed['language_code']);
        $this->assertStringContainsString('Total paid', $completed['message']);
        $this->assertSame(['customer_name', 'order_id', 'order_total'], $completed['variables']);
        $this->assertSame(['body_1', 'body_2', 'body_3'], $completed['component_keys']);
        $this->assertCount(2, $completed['buttons']);
    }

    public function test_merge_upgrades_legacy_five_parameter_billing_mapping(): void
    {
        $merged = collect(WhatsAppTemplateCatalog::merge([[
            'id' => 'billing_sent',
            'template_id' => 'billing_sent',
            'event' => 'billing_sent',
            'provider' => 'nexmsg',
            'approval_status' => 'approved',
            'variables' => ['greeting', 'customer_name', 'invoice_number', 'bill_total', 'payment_link'],
            'component_keys' => ['body_1', 'body_2', 'body_3', 'body_4', 'body_5'],
        ]]))->keyBy('id');

        $this->assertSame(['customer_name', 'invoice_number', 'bill_total', 'payment_link'], $merged['billing_sent']['variables']);
        $this->assertSame(['body_1', 'body_2', 'body_3', 'button_url_1'], $merged['billing_sent']['component_keys']);
        $this->assertSame('nexmsg', $merged['billing_sent']['provider']);
        $this->assertSame('approved', $merged['billing_sent']['approval_status']);
    }

    public function test_merge_upgrades_legacy_order_submitted_mapping_with_tracking_button(): void
    {
        $merged = collect(WhatsAppTemplateCatalog::merge([[
            'id' => 'order_submitted',
            'template_id' => 'order_submitted',
            'event' => 'order_submitted',
            'variables' => ['customer_name', 'restaurant_name', 'order_id', 'order_total'],
            'component_keys' => ['body_1', 'body_2', 'body_3', 'body_4'],
            'language_code' => 'en',
            'is_active' => true,
        ]]))->keyBy('id');

        $this->assertSame(
            ['customer_name', 'restaurant_name', 'order_id', 'order_total', 'tracking_link'],
            $merged['order_submitted']['variables'],
        );
        $this->assertSame(
            ['body_1', 'body_2', 'body_3', 'body_4', 'button_url_1'],
            $merged['order_submitted']['component_keys'],
        );
        $this->assertSame('tracking_link', data_get($merged, 'order_submitted.buttons.0.variable'));
    }

    public function test_merge_replaces_provider_body_placeholders_with_order_parameter_names(): void
    {
        $merged = collect(WhatsAppTemplateCatalog::merge([[
            'id' => 'order_submitted',
            'template_id' => 'order_submitted',
            'event' => 'order_submitted',
            'provider' => 'nexmsg',
            'approval_status' => 'approved',
            'variables' => ['body_1', 'body_2', 'body_3', 'body_4', 'tracking_link'],
            'component_keys' => ['body_1', 'body_2', 'body_3', 'body_4', 'button_url_1'],
            'buttons' => [[
                'type' => 'url',
                'index' => 0,
                'label' => 'Track Order',
                'variable' => 'tracking_link',
            ]],
        ]]))->keyBy('id');

        $this->assertSame(
            ['customer_name', 'restaurant_name', 'order_id', 'order_total', 'tracking_link'],
            $merged['order_submitted']['variables'],
        );
        $this->assertSame('nexmsg', $merged['order_submitted']['provider']);
        $this->assertSame('approved', $merged['order_submitted']['approval_status']);
    }

    public function test_provider_sync_cannot_erase_stock_automation_event(): void
    {
        $ready = collect(WhatsAppTemplateCatalog::merge([[
            'id' => 'order_ready',
            'template_id' => 'order_ready',
            'event' => null,
            'approval_status' => 'approved',
        ]]))->firstWhere('id', 'order_ready');

        $this->assertSame('ready', $ready['event']);
        $this->assertSame('approved', $ready['approval_status']);
    }
}
