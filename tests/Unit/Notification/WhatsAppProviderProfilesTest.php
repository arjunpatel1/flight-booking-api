<?php

namespace Tests\Unit\Notification;

use Illuminate\Support\Facades\Http;
use Modules\Notification\Services\WhatsApp\Providers\MetaWhatsAppProvider;
use Modules\Notification\Services\WhatsApp\Providers\Msg91Provider;
use Modules\Notification\Services\WhatsApp\Providers\NexMsgProvider;
use Modules\Notification\Services\WhatsApp\WhatsAppIntegrationService;
use Tests\TestCase;

class WhatsAppProviderProfilesTest extends TestCase
{
    public function test_nexmsg_sync_maps_manu_approved_aliases_to_order_events_and_parameters(): void
    {
        $this->settings([
            'whatsapp_nexmsg_account_id' => 'account-123',
            'whatsapp_templates' => [],
        ]);

        $result = app(WhatsAppIntegrationService::class)->syncTemplates('nexmsg', null, [
            [
                'accountId' => 'account-123', 'name' => 'order_confirmation',
                'status' => 'APPROVED', 'enabled' => true, 'language' => 'en',
                'body' => 'Dear {{1}}, {{2}} invoice {{3}} amount {{4}} date {{5}} quantity {{6}}.',
            ],
            [
                'accountId' => 'account-123', 'name' => 'order_delivered_successfully',
                'status' => 'APPROVED', 'enabled' => true, 'language' => 'en',
                'body' => 'Hi {{1}}, {{2}} delivered {{3}} amount {{4}} on {{5}}.',
            ],
        ]);

        $templates = collect($result['templates'])->keyBy('template_id');
        $this->assertSame('order_accepted', $templates['order_confirmation']['event']);
        $this->assertSame(
            ['customer_name', 'restaurant_name', 'order_id', 'order_total_numeric', 'order_date', 'item_quantity'],
            $templates['order_confirmation']['variables'],
        );
        $this->assertSame('completed', $templates['order_delivered_successfully']['event']);
        $this->assertSame(
            ['customer_name', 'restaurant_name', 'order_id', 'order_total_numeric', 'delivered_at'],
            $templates['order_delivered_successfully']['variables'],
        );
    }

    public function test_nexmsg_sync_ignores_unrelated_templates_from_a_shared_provider_account(): void
    {
        $this->settings(['whatsapp_nexmsg_account_id' => 'account-123', 'whatsapp_templates' => []]);

        $result = app(WhatsAppIntegrationService::class)->syncTemplates('nexmsg', null, [[
            'accountId' => 'account-123', 'name' => 'teacher_leave_approved',
            'status' => 'APPROVED', 'enabled' => true, 'body' => 'Leave {{1}} approved',
        ], [
            'accountId' => 'account-123', 'name' => 'order_submitted',
            'status' => 'APPROVED', 'enabled' => true, 'body' => 'Order {{1}} received',
        ], [
            'accountId' => 'account-123', 'name' => 'restaurant_custom_notice',
            'event' => 'custom_restaurant_event', 'status' => 'APPROVED', 'enabled' => true,
            'body' => 'Restaurant update {{1}}',
        ]]);

        $this->assertSame(
            ['order_submitted', 'restaurant_custom_notice'],
            collect($result['templates'])->pluck('template_id')->all(),
        );
    }

    public function test_nexmsg_sync_replaces_positional_body_aliases_with_stock_variables(): void
    {
        $this->settings(['whatsapp_nexmsg_account_id' => 'account-123', 'whatsapp_templates' => []]);

        $result = app(WhatsAppIntegrationService::class)->syncTemplates('nexmsg', null, [[
            'accountId' => 'account-123',
            'name' => 'order_submitted',
            'status' => 'APPROVED',
            'enabled' => true,
            'body' => 'Hi {{1}}, restaurant {{2}} received order {{3}} total {{4}}.',
            'variables' => ['body_1', 'body_2', 'body_3', 'body_4'],
            'buttons' => [[
                'type' => 'URL',
                'text' => 'Track Order',
                'url' => 'https://tenant.test/orders/{{1}}',
            ]],
        ]]);

        $submitted = collect($result['templates'])->firstWhere('template_id', 'order_submitted');

        $this->assertSame(
            ['customer_name', 'restaurant_name', 'order_id', 'order_total', 'tracking_link'],
            $submitted['variables'],
        );
        $this->assertSame(
            ['body_1', 'body_2', 'body_3', 'body_4', 'button_url_1'],
            $submitted['component_keys'],
        );
    }

    public function test_nexmsg_sync_keeps_pending_and_rejected_templates_visible(): void
    {
        $this->settings(['whatsapp_nexmsg_account_id' => 'account-123', 'whatsapp_templates' => []]);

        $result = app(WhatsAppIntegrationService::class)->syncTemplates('nexmsg', null, [
            ['accountId' => 'account-123', 'name' => 'pending_order', 'event' => 'pending_test', 'status' => 'PENDING', 'enabled' => true],
            ['accountId' => 'account-123', 'name' => 'rejected_order', 'event' => 'rejected_test', 'status' => 'REJECTED', 'enabled' => true, 'reason' => 'Body does not match policy'],
        ]);

        $templates = collect($result['templates'])->keyBy('template_id');
        $this->assertSame('pending', $templates['pending_order']['approval_status']);
        $this->assertFalse($templates['pending_order']['is_active']);
        $this->assertSame('rejected', $templates['rejected_order']['approval_status']);
        $this->assertSame('Body does not match policy', $templates['rejected_order']['provider_rejection_reason']);
    }

    public function test_nexmsg_sync_activates_approved_title_case_replacement_and_retires_legacy_body(): void
    {
        $this->settings(['whatsapp_nexmsg_account_id' => 'account-123', 'whatsapp_templates' => []]);

        $result = app(WhatsAppIntegrationService::class)->syncTemplates('nexmsg', null, [[
            'accountId' => 'account-123',
            'name' => 'nexdine_staff_order_details_v2',
            'status' => 'APPROVED',
            'enabled' => true,
            'body' => '*New restaurant order* 🔔' . "\n\n" . 'Order: *{{1}}*',
        ], [
            'accountId' => 'account-123',
            'name' => 'nexdine_staff_order_details_v3',
            'status' => 'APPROVED',
            'enabled' => true,
            'body' => '*New Restaurant Order* 🔔' . "\n\n" . 'Order: *{{1}}*',
        ]]);

        $templates = collect($result['templates'])->keyBy('template_id');
        $this->assertFalse($templates['nexdine_staff_order_details_v2']['is_active']);
        $this->assertTrue($templates['nexdine_staff_order_details_v3']['is_active']);
        $this->assertSame('staff_order_received', $templates['nexdine_staff_order_details_v3']['event']);
        $this->assertStringStartsWith(
            '*New Restaurant Order* 🔔',
            $templates['nexdine_staff_order_details_v3']['message'],
        );
    }

    public function test_pending_title_case_replacement_does_not_disable_approved_legacy_template(): void
    {
        $this->settings(['whatsapp_nexmsg_account_id' => 'account-123', 'whatsapp_templates' => []]);

        $result = app(WhatsAppIntegrationService::class)->syncTemplates('nexmsg', null, [[
            'accountId' => 'account-123',
            'name' => 'order_completed',
            'status' => 'APPROVED',
            'enabled' => true,
            'body' => '*Order completed* ✅' . "\n\n" . 'Thank you, {{1}}. Order: {{2}} Total: {{3}}',
        ], [
            'accountId' => 'account-123',
            'name' => 'nexdine_order_completed_v2',
            'status' => 'PENDING',
            'enabled' => true,
            'body' => '*Order Completed* ✅' . "\n\n" . 'Thank you, {{1}}. Order: {{2}} Total: {{3}}',
        ]]);

        $templates = collect($result['templates'])->keyBy('template_id');
        $this->assertTrue($templates['order_completed']['is_active']);
        $this->assertFalse($templates['nexdine_order_completed_v2']['is_active']);
    }

    public function test_full_sync_removes_stale_templates_from_a_previous_provider_account(): void
    {
        $this->settings([
            'whatsapp_nexmsg_account_id' => 'account-new',
            'whatsapp_templates' => [[
                'id' => 'old_action', 'template_id' => 'old_action', 'event' => 'whatsapp_order_received',
                'provider' => 'nexmsg', 'approval_status' => 'approved', 'is_active' => true,
            ], [
                'id' => 'local_note', 'template_id' => 'local_note', 'event' => 'local', 'is_active' => true,
            ]],
        ]);

        app(WhatsAppIntegrationService::class)->syncTemplates('nexmsg', null, [[
            'accountId' => 'account-new', 'name' => 'order_submitted', 'status' => 'APPROVED',
            'enabled' => true, 'body' => 'Order {{1}}',
        ]]);

        $templates = collect(setting('whatsapp_templates'))->keyBy('template_id');
        $this->assertFalse($templates->has('old_action'));
        $this->assertTrue($templates->has('local_note'));
        $this->assertTrue($templates->has('order_submitted'));
    }

    public function test_status_only_sync_preserves_local_content_and_review_state(): void
    {
        $this->settings([
            'whatsapp_nexmsg_account_id' => 'account-123',
            'whatsapp_templates' => [[
                'id' => 'order_notice', 'template_id' => 'order_notice', 'name' => 'Order notice',
                'message' => 'Locally edited body', 'approval_status' => 'local_changes', 'is_active' => true,
            ]],
        ]);

        app(WhatsAppIntegrationService::class)->syncTemplates('nexmsg', null, [[
            'accountId' => 'account-123', 'name' => 'order_notice', 'status' => 'APPROVED',
            'enabled' => true, 'body' => 'Provider body',
        ]], true);

        $template = collect(setting('whatsapp_templates'))->firstWhere('template_id', 'order_notice');
        $this->assertSame('Locally edited body', $template['message']);
        $this->assertSame('local_changes', $template['approval_status']);
        $this->assertSame('approved', $template['provider_approval_status']);
    }

    public function test_msg91_routes_marketing_templates_to_the_marketing_profile(): void
    {
        $this->settings([
            'whatsapp_msg91_marketing_reuse_utility' => false,
            'whatsapp_msg91_utility_auth_key' => 'utility-key',
            'whatsapp_msg91_utility_integrated_number' => '911111111111',
            'whatsapp_msg91_marketing_auth_key' => 'marketing-key',
            'whatsapp_msg91_marketing_integrated_number' => '922222222222',
            'whatsapp_msg91_api_url' => 'https://api.msg91.test/send',
            'whatsapp_templates' => [['id' => 'offer', 'name' => 'offer', 'category' => 'marketing', 'namespace' => 'ns', 'language_code' => 'en']],
        ]);
        Http::fake(['api.msg91.test/*' => Http::response(['request_id' => 'one'], 200)]);

        app(Msg91Provider::class)->sendTemplate('933333333333', 'offer');

        Http::assertSent(fn ($request) => $request->hasHeader('authkey', 'marketing-key')
            && $request['integrated_number'] === '922222222222');
    }

    public function test_meta_provider_sends_an_actual_graph_template_request(): void
    {
        $this->settings([
            'whatsapp_meta_access_token' => 'meta-token',
            'whatsapp_meta_phone_number_id' => '12345',
            'whatsapp_meta_graph_version' => '23.0',
            'whatsapp_templates' => [['id' => 'receipt', 'name' => 'receipt', 'language_code' => 'en', 'variables' => ['order']]],
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        $result = app(MetaWhatsAppProvider::class)->sendTemplate('919999999999', 'receipt', ['order' => 'A-1']);

        $this->assertSame('meta', $result['provider']);
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v23.0/12345/messages'
            && $request->hasHeader('Authorization', 'Bearer meta-token')
            && $request['type'] === 'template');
    }

    public function test_managed_nexmsg_credentials_override_stale_tenant_credentials_during_sync(): void
    {
        config(['notification.providers.nexmsg.api_url' => 'https://api-nexmsg.test/api/send/template']);
        $this->settings([
            'whatsapp_nexmsg_account_id' => 'stale-account',
            'whatsapp_nexmsg_auth_key' => 'stale-key',
            'whatsapp_templates' => [],
        ]);
        Http::fake(['api-nexmsg.test/api/templates*' => Http::response([[
            'accountId' => 'managed-account', 'name' => 'order_submitted',
            'status' => 'APPROVED', 'enabled' => true, 'body' => 'Order {{1}}',
        ]])]);
        $service = new class extends WhatsAppIntegrationService
        {
            protected function managedNexMsgCredentials(): array
            {
                return ['account_id' => 'managed-account', 'auth_key' => 'managed-key'];
            }
        };

        $result = $service->syncTemplates('nexmsg');

        $this->assertSame(1, $result['count']);
        Http::assertSent(fn ($request) => $request->hasHeader('authkey', 'managed-key')
            && str_contains($request->url(), 'accountId=managed-account')
            && ! str_contains($request->url(), 'stale-account'));
    }

    public function test_managed_nexmsg_credentials_override_stale_tenant_credentials_during_send(): void
    {
        config(['notification.providers.nexmsg.api_url' => 'https://api-nexmsg.test/api/send/template']);
        $this->settings([
            'whatsapp_nexmsg_account_id' => 'stale-account',
            'whatsapp_nexmsg_auth_key' => 'stale-key',
            'whatsapp_templates' => [[
                'id' => 'order_submitted', 'template_id' => 'managed_order_submitted',
                'language_code' => 'en', 'variables' => ['order_id'], 'component_keys' => ['body_1'],
            ]],
        ]);
        Http::fake(['api-nexmsg.test/*' => Http::response(['messageId' => 'wamid.managed'], 200)]);
        $provider = new class extends NexMsgProvider
        {
            protected function managedCredentials(): array
            {
                return ['account_id' => 'managed-account', 'auth_key' => 'managed-key'];
            }
        };

        $provider->sendTemplate('919999999999', 'order_submitted', ['order_id' => 'ORD-MOVED']);

        Http::assertSent(fn ($request) => $request->hasHeader('authkey', 'managed-key')
            && $request['accountId'] === 'managed-account');
    }

    public function test_nexmsg_provider_sends_account_template_and_components_contract(): void
    {
        config(['notification.providers.nexmsg.api_url' => 'https://api-nexmsg.test/api/send/template']);
        $this->settings([
            'whatsapp_nexmsg_account_id' => 'account-123',
            'whatsapp_nexmsg_auth_key' => 'secret-key',
            'whatsapp_templates' => [[
                'id' => 'customer_login_otp',
                'template_id' => 'auth_login_verification',
                'name' => 'Customer OTP',
                'language_code' => 'en',
                'variables' => ['otp', 'button_code'],
                'component_keys' => ['body_1', 'button_1'],
            ]],
        ]);
        Http::fake(['api-nexmsg.test/*' => Http::response(['messageId' => 'wamid.1'], 200)]);

        $result = app(NexMsgProvider::class)->sendTemplate('91 98765-43210', 'customer_login_otp', [
            'otp' => '123456',
            'button_code' => '123456',
        ]);

        $this->assertSame('nexmsg', $result['provider']);
        Http::assertSent(fn ($request) => $request->hasHeader('authkey', 'secret-key')
            && $request['accountId'] === 'account-123'
            && $request['templateName'] === 'auth_login_verification'
            && $request['to'] === '919876543210'
            && $request['components'][0]['type'] === 'body'
            && $request['components'][1]['type'] === 'button'
            && $request['components'][1]['parameters'][0]['text'] === '123456');
    }

    public function test_nexmsg_normalizes_control_whitespace_in_dynamic_parameters(): void
    {
        config(['notification.providers.nexmsg.api_url' => 'https://api-nexmsg.test/api/send/template']);
        $this->settings([
            'whatsapp_nexmsg_account_id' => 'account-123', 'whatsapp_nexmsg_auth_key' => 'secret-key',
            'whatsapp_templates' => [[
                'id' => 'delivery_update', 'template_id' => 'address_delivery_update',
                'variables' => ['delivery_status', 'rider_name'], 'component_keys' => ['body_1', 'body_2'],
            ]],
        ]);
        Http::fake(['api-nexmsg.test/*' => Http::response(['messageId' => 'wamid.2'], 200)]);

        app(NexMsgProvider::class)->sendTemplate('919999999999', 'delivery_update', [
            'delivery_status' => "Rider assigned\nOTP: 5861", 'rider_name' => "Test\t    Rider",
        ]);

        Http::assertSent(fn ($request) => data_get($request->data(), 'components.0.parameters.0.text') === 'Rider assigned OTP: 5861'
            && data_get($request->data(), 'components.0.parameters.1.text') === 'Test Rider');
    }

    public function test_nexmsg_provider_exposes_a_safe_provider_delivery_error(): void
    {
        config(['notification.providers.nexmsg.api_url' => 'https://api-nexmsg.test/api/send/template']);
        $this->settings([
            'whatsapp_nexmsg_account_id' => 'account-123',
            'whatsapp_nexmsg_auth_key' => 'secret-key',
            'whatsapp_templates' => [[
                'id' => 'customer_login_otp',
                'template_id' => 'auth_login_verification',
                'variables' => ['otp'],
                'component_keys' => ['body_1'],
            ]],
        ]);
        Http::fake(['api-nexmsg.test/*' => Http::response([
            'error' => 'Unknown error',
            'results' => [['phone' => '919999999999', 'success' => false, 'error' => 'Template mismatch']],
        ], 400)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('NexMsg rejected the message (HTTP 400): Template mismatch');

        app(NexMsgProvider::class)->sendTemplate('919999999999', 'customer_login_otp', ['otp' => '123456']);
    }

    public function test_nexmsg_uses_the_exact_tenant_approved_otp_template_name(): void
    {
        config(['notification.providers.nexmsg.api_url' => 'https://api-nexmsg.test/api/send/template']);
        $this->settings([
            'whatsapp_nexmsg_account_id' => 'account-123',
            'whatsapp_nexmsg_auth_key' => 'secret-key',
            'whatsapp_templates' => [[
                'id' => 'customer_login_otp',
                'template_id' => 'customer_login_otp',
                'language_code' => 'en',
                'variables' => ['otp', 'button_code'],
                'component_keys' => ['body_1', 'button_1'],
            ]],
        ]);
        Http::fake(['api-nexmsg.test/*' => Http::response(['messageId' => 'wamid.legacy'], 200)]);

        app(NexMsgProvider::class)->sendTemplate('919876543210', 'customer_login_otp', [
            'otp' => '654321',
            'button_code' => '654321',
        ]);

        Http::assertSent(fn ($request) => $request['templateName'] === 'customer_login_otp'
            && $request['components'][0]['parameters'][0]['text'] === '654321'
            && $request['components'][1]['parameters'][0]['text'] === '654321');
    }

    public function test_nexmsg_serializes_receipt_reorder_and_feedback_buttons(): void
    {
        config(['notification.providers.nexmsg.api_url' => 'https://api-nexmsg.test/api/send/template']);
        $this->settings([
            'whatsapp_nexmsg_account_id' => 'account-123',
            'whatsapp_nexmsg_auth_key' => 'secret-key',
            'whatsapp_templates' => [[
                'id' => 'order_completed', 'template_id' => 'order_completed_advanced', 'language_code' => 'en',
                'variables' => ['customer_name', 'payment_link', 'order_again_link', 'feedback_reply'],
                'component_keys' => ['body_1', 'button_url_1', 'button_url_2', 'button_quick_reply_3'],
            ]],
        ]);
        Http::fake(['api-nexmsg.test/*' => Http::response(['messageId' => 'wamid.completed'], 200)]);

        app(NexMsgProvider::class)->sendTemplate('919876543210', 'order_completed', [
            'customer_name' => 'Aarav', 'payment_link' => 'receipt/42',
            'order_again_link' => 'menu/branch', 'feedback_reply' => 'happy:42',
        ]);

        Http::assertSent(fn ($request) => $request['components'][1]['sub_type'] === 'url'
            && $request['components'][2]['parameters'][0]['text'] === 'menu/branch'
            && $request['components'][3]['sub_type'] === 'quick_reply'
            && $request['components'][3]['parameters'][0]['payload'] === 'happy:42');
    }

    public function test_nexmsg_serializes_tracking_cancel_and_staff_order_button_suffixes(): void
    {
        config(['notification.providers.nexmsg.api_url' => 'https://api-nexmsg.test/api/send/template']);
        $this->settings([
            'whatsapp_nexmsg_account_id' => 'account-123',
            'whatsapp_nexmsg_auth_key' => 'secret-key',
            'whatsapp_templates' => [[
                'id' => 'order_submitted', 'template_id' => 'nexdine_order_received_actions_v2', 'language_code' => 'en',
                'variables' => ['customer_name', 'tracking_link', 'cancel_link'],
                'component_keys' => ['body_1', 'button_url_1', 'button_url_2'],
            ], [
                'id' => 'staff_order_received_professional', 'template_id' => 'nexdine_staff_order_details_v2', 'language_code' => 'en',
                'variables' => ['order_number', 'order_link'],
                'component_keys' => ['body_1', 'button_url_1'],
            ]],
        ]);
        Http::fake(['api-nexmsg.test/*' => Http::response(['messageId' => 'wamid.actions'], 200)]);

        app(NexMsgProvider::class)->sendTemplate('919876543210', 'order_submitted', [
            'customer_name' => 'Aarav',
            'tracking_link' => 'https://tenant.test/online-menu/menu/orders/ORD-ABC12345',
            'cancel_link' => 'https://api.nexdine.test/v1/customer-app/order-cancel/secure-token',
        ]);
        app(NexMsgProvider::class)->sendTemplate('919876543210', 'staff_order_received_professional', [
            'order_number' => 'ORD-ABC12345',
            'order_link' => 'https://tenant.test/admin/orders/1500/show',
        ]);

        Http::assertSent(fn ($request) => $request['templateName'] === 'nexdine_order_received_actions_v2'
            && $request['components'][1]['parameters'][0]['text'] === 'ORD-ABC12345'
            && $request['components'][2]['parameters'][0]['text'] === 'secure-token');
        Http::assertSent(fn ($request) => $request['templateName'] === 'nexdine_staff_order_details_v2'
            && $request['components'][1]['parameters'][0]['text'] === '1500');
    }

    public function test_nexmsg_prefers_exact_provider_template_when_event_ids_are_shared(): void
    {
        config(['notification.providers.nexmsg.api_url' => 'https://api-nexmsg.test/api/send/template']);
        $this->settings([
            'whatsapp_nexmsg_account_id' => 'account-123',
            'whatsapp_nexmsg_auth_key' => 'secret-key',
            'whatsapp_templates' => [[
                'id' => 'order_accepted',
                'template_id' => 'order_accepted',
                'variables' => ['customer_name', 'order_id', 'estimated_time'],
                'component_keys' => ['body_1', 'body_2', 'body_3'],
            ], [
                'id' => 'order_accepted',
                'template_id' => 'nexdine_order_confirmed_track_v2',
                'variables' => ['customer_name', 'order_id', 'estimated_time', 'tracking_link'],
                'component_keys' => ['body_1', 'body_2', 'body_3', 'button_url_1'],
            ]],
        ]);
        Http::fake(['api-nexmsg.test/*' => Http::response(['messageId' => 'wamid.confirmed'], 200)]);

        app(NexMsgProvider::class)->sendTemplate('919876543210', 'nexdine_order_confirmed_track_v2', [
            'customer_name' => 'Aarav',
            'order_id' => 'ORD-ABC12345',
            'estimated_time' => '20 minutes',
            'tracking_link' => 'https://tenant.test/online-menu/menu/orders/ORD-ABC12345',
        ]);

        Http::assertSent(fn ($request) => $request['templateName'] === 'nexdine_order_confirmed_track_v2'
            && count($request['components'][0]['parameters']) === 3
            && $request['components'][1]['parameters'][0]['text'] === 'ORD-ABC12345');
    }

    public function test_nexmsg_sends_only_invoice_uuid_to_the_approved_receipt_url_button(): void
    {
        config(['notification.providers.nexmsg.api_url' => 'https://api-nexmsg.test/api/send/template']);
        $this->settings([
            'whatsapp_nexmsg_account_id' => 'account-123',
            'whatsapp_nexmsg_auth_key' => 'secret-key',
            'whatsapp_templates' => [[
                'id' => 'billing_sent', 'template_id' => 'billing_sent', 'language_code' => 'en',
                'variables' => ['customer_name', 'invoice_number', 'bill_total', 'payment_link'],
                'component_keys' => ['body_1', 'body_2', 'body_3', 'button_url_1'],
            ]],
        ]);
        Http::fake(['api-nexmsg.test/*' => Http::response(['messageId' => 'wamid.bill'], 200)]);

        app(NexMsgProvider::class)->sendTemplate('919876543210', 'billing_sent', [
            'customer_name' => 'Aarav',
            'invoice_number' => 'INV-1',
            'bill_total' => 'INR 100.00',
            'payment_link' => 'https://api.nexdine.test/i/8d386c55-268d-4c60-a255-081142c498bd',
        ]);

        Http::assertSent(fn ($request) => count($request['components'][0]['parameters']) === 3
            && $request['components'][1]['parameters'][0]['text'] === '8d386c55-268d-4c60-a255-081142c498bd');
    }

    public function test_meta_serializes_url_and_quick_reply_template_buttons(): void
    {
        $this->settings([
            'whatsapp_meta_access_token' => 'meta-token', 'whatsapp_meta_phone_number_id' => '12345',
            'whatsapp_templates' => [[
                'id' => 'completed', 'template_id' => 'completed', 'language_code' => 'en',
                'variables' => ['name', 'receipt', 'reaction'],
                'component_keys' => ['body_1', 'button_url_1', 'button_quick_reply_2'],
            ]],
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.2']]], 200)]);

        app(MetaWhatsAppProvider::class)->sendTemplate('919999999999', 'completed', [
            'name' => 'Aarav', 'receipt' => 'receipt/42', 'reaction' => 'happy:42',
        ]);

        Http::assertSent(fn ($request) => $request['template']['components'][1]['sub_type'] === 'url'
            && $request['template']['components'][2]['sub_type'] === 'quick_reply'
            && $request['template']['components'][2]['parameters'][0]['payload'] === 'happy:42');
    }

    public function test_msg91_missing_namespace_is_reported_without_sending(): void
    {
        $this->settings([
            'whatsapp_msg91_utility_auth_key' => 'secret',
            'whatsapp_msg91_utility_integrated_number' => '911111111111',
            'whatsapp_msg91_api_url' => 'https://api.msg91.test/send',
            'whatsapp_templates' => [['id' => 'receipt', 'name' => 'receipt']],
        ]);
        Http::fake();
        try {
            app(Msg91Provider::class)->sendTemplate('919876543210', 'receipt');
            $this->fail('Missing namespace must fail before HTTP.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('template namespace', $exception->getMessage());
            $this->assertStringNotContainsString('secret', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    private function settings(array $values): void
    {
        app()->instance('setting', new class($values)
        {
            public function __construct(private array $values) {}

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            public function set(array $values): void
            {
                $this->values = [...$this->values, ...$values];
            }
        });
    }
}
