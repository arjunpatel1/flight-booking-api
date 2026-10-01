<?php

namespace Tests\Unit\WhatsAppCenter;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\WhatsAppCenter\Models\WhatsAppPhoneNumber;
use Modules\WhatsAppCenter\Models\WhatsAppProviderProfile;
use Modules\WhatsAppCenter\Services\Providers\MetaOrderingProvider;
use Modules\WhatsAppCenter\Services\Providers\Msg91OrderingProvider;
use Modules\WhatsAppCenter\Services\Providers\NexMsgOrderingProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WhatsAppProviderAdapterTest extends TestCase
{
    public function test_meta_adapter_normalizes_only_safe_fields(): void
    {
        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => 'phone-a'],
                        'messages' => [[
                            'id' => 'event-a', 'from' => '919999999999', 'type' => 'text',
                            'text' => ['body' => 'MENU'], 'private' => 'discard',
                        ]],
                    ],
                ]],
            ]],
        ];
        $request = Request::create('/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $message = app(MetaOrderingProvider::class)->normalizeInbound($request);

        $this->assertSame('event-a', $message->providerEventId);
        $this->assertSame('phone-a', $message->providerPhoneId);
        $this->assertSame('MENU', $message->text);
        $this->assertArrayNotHasKey('private', $message->safePayload);
    }

    public function test_meta_location_is_extracted_without_copying_private_webhook_fields(): void
    {
        $payload = ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => 'phone-a'],
            'messages' => [[
                'id' => 'location-1', 'from' => '917389175732', 'type' => 'location',
                'timestamp' => now()->timestamp,
                'location' => ['latitude' => 23.02, 'longitude' => 72.57,
                    'name' => 'Pickup point', 'address' => 'Main road', 'private' => 'discard'],
            ]],
        ]]]]]];
        $request = Request::create('/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $message = app(MetaOrderingProvider::class)->normalizeInbound($request);

        $this->assertSame('location', $message->type);
        $this->assertSame(23.02, $message->location['latitude']);
        $this->assertSame(72.57, $message->location['longitude']);
        $this->assertArrayNotHasKey('private', $message->location);
        $this->assertArrayNotHasKey('location', $message->safePayload);
    }

    public function test_msg91_adapter_ignores_tenant_fields_in_payload(): void
    {
        $payload = ['integrated_number' => 'phone-b', 'event_id' => 'event-b', 'from' => '918888888888', 'text' => 'CART', 'tenant_id' => 999];
        $request = Request::create('/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $message = app(Msg91OrderingProvider::class)->normalizeInbound($request);

        $this->assertSame('event-b', $message->providerEventId);
        $this->assertSame('CART', $message->text);
        $this->assertArrayNotHasKey('tenant_id', $message->safePayload);
    }

    public function test_bad_signature_is_rejected(): void
    {
        $profile = new WhatsAppProviderProfile;
        $profile->credentials = ['webhook_secret' => '0123456789abcdef'];
        $request = Request::create('/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256=bad',
        ], '{}');

        $this->expectException(HttpException::class);
        app(Msg91OrderingProvider::class)->verifyWebhook($request, $profile);
    }

    public function test_nexmsg_adapter_extracts_waba_and_normalizes_indian_recipient(): void
    {
        $payload = [
            'entry' => [[
                'id' => '987670750675913',
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id' => 'wamid-safe',
                            'from' => '+91 99999 99999',
                            'type' => 'text',
                            'text' => ['body' => 'Hi'],
                            'private' => 'discard',
                        ]],
                    ],
                ]],
            ]],
        ];
        $request = Request::create('/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));

        $message = app(NexMsgOrderingProvider::class)->normalizeInbound($request);

        $this->assertSame('987670750675913', $message->providerPhoneId);
        $this->assertSame('919999999999', $message->sender);
        $this->assertSame('Hi', $message->text);
        $this->assertArrayNotHasKey('private', $message->safePayload);
    }

    public function test_nexmsg_adapter_normalizes_interactive_fulfilment_choice_and_location(): void
    {
        $choicePayload = ['wabaId' => '987670750675913', 'event_id' => 'choice-1',
            'from' => '919999999999', 'type' => 'interactive',
            'message' => ['interactive' => ['button_reply' => ['id' => 'delivery', 'title' => 'Home delivery']]]];
        $choiceRequest = Request::create('/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($choicePayload));
        $choice = app(NexMsgOrderingProvider::class)->normalizeInbound($choiceRequest);

        $this->assertSame('delivery', $choice->text);

        $locationPayload = ['wabaId' => '987670750675913', 'event_id' => 'location-1',
            'from' => '919999999999', 'type' => 'location',
            'location' => ['latitude' => 28.4, 'longitude' => 77.3, 'name' => 'Home', 'address' => 'Main road']];
        $locationRequest = Request::create('/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($locationPayload));
        $location = app(NexMsgOrderingProvider::class)->normalizeInbound($locationRequest);

        $this->assertSame(28.4, $location->location['latitude']);
        $this->assertSame(77.3, $location->location['longitude']);
        $this->assertSame('Home', $location->location['name']);
    }

    public function test_nexmsg_adapter_normalizes_catalog_order_without_accepting_provider_price(): void
    {
        $payload = [
            'wabaId' => '987670750675913',
            'event_id' => 'wamid-order-1',
            'from' => '919999999999',
            'type' => 'order',
            'timestamp' => now()->timestamp,
            'order' => [
                'id' => 'provider-order-1',
                'catalog_id' => 'catalog-1',
                'items' => [[
                    'product_retailer_id' => 'NX-PROD-001',
                    'quantity' => 2,
                    'item_price' => '0.01',
                    'currency' => 'INR',
                ]],
            ],
        ];
        $request = Request::create('/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));

        $message = app(NexMsgOrderingProvider::class)->normalizeInbound($request);

        $this->assertSame('order', $message->type);
        $this->assertSame('provider-order-1', $message->providerOrderId);
        $this->assertSame('catalog-1', $message->catalogId);
        $this->assertSame([['product_retailer_id' => 'NX-PROD-001', 'quantity' => 2]], $message->orderItems);
        $this->assertArrayNotHasKey('item_price', $message->orderItems[0]);
    }

    public function test_nexmsg_accepts_the_exact_signed_body_emitted_by_the_gateway(): void
    {
        $secret = 'account-specific-signing-secret-123456';
        $payload = [
            'wabaId' => '987670750675913',
            'phone_number_id' => '1234567890',
            'event_id' => 'wamid-signed-order-1',
            'event_type' => 'whatsapp.order.received',
            'from' => '919999999999',
            'type' => 'order',
            'timestamp' => 1789011000,
            'order' => [
                'id' => 'provider-order-signed-1',
                'catalog_id' => 'catalog-1',
                'items' => [['product_retailer_id' => 'NX-PROD-001', 'quantity' => 2]],
            ],
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $profile = new WhatsAppProviderProfile;
        $profile->credentials = ['webhook_secret' => $secret];
        $request = Request::create('/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256='.hash_hmac('sha256', $raw, $secret),
        ], $raw);

        $provider = app(NexMsgOrderingProvider::class);
        $provider->verifyWebhook($request, $profile);
        $message = $provider->normalizeInbound($request);

        $this->assertSame('wamid-signed-order-1', $message->providerEventId);
        $this->assertSame('provider-order-signed-1', $message->providerOrderId);
        $this->assertSame([['product_retailer_id' => 'NX-PROD-001', 'quantity' => 2]], $message->orderItems);
    }

    public function test_nexmsg_catalog_request_uses_fixed_endpoint_and_does_not_send_account_id(): void
    {
        config(['whatsappcenter.nexmsg.catalog_send_url' => 'https://api-nexmsg.myteknoland.com/api/catalog/send']);
        Http::fake([
            'api-nexmsg.myteknoland.com/*' => Http::response([
                'success' => true, 'messageId' => 'wamid-safe',
            ]),
        ]);
        $profile = new WhatsAppProviderProfile;
        $profile->credentials = ['auth_key' => 'secret-auth-key', 'account_id' => 'diagnostic-only'];
        $phone = new WhatsAppPhoneNumber(['provider_phone_id' => '987670750675913']);

        $result = app(NexMsgOrderingProvider::class)->sendCatalog(
            $profile, $phone, '9999999999', 'Welcome!', 'Tap below to view full menu', 'whatsapp-catalog-message:42',
        );

        $this->assertSame('wamid-safe', $result['provider_message_id']);
        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $request->url() === 'https://api-nexmsg.myteknoland.com/api/catalog/send'
                && $request->hasHeader('authkey', 'secret-auth-key')
                && $request->hasHeader('Idempotency-Key', 'whatsapp-catalog-message:42')
                && $payload['wabaId'] === '987670750675913'
                && $payload['to'] === '919999999999'
                && ! array_key_exists('accountId', $payload)
                && ! array_key_exists('auth_key', $payload);
        });
    }

    public function test_nexmsg_order_type_buttons_use_fixed_endpoint_and_bounded_choices(): void
    {
        config(['whatsappcenter.nexmsg.order_type_send_url' => 'https://api-nexmsg.myteknoland.com/api/catalog/order-type']);
        Http::fake(['api-nexmsg.myteknoland.com/*' => Http::response(['success' => true, 'messageId' => 'wamid-choice'])]);
        $profile = new WhatsAppProviderProfile;
        $profile->credentials = ['auth_key' => 'secret-auth-key'];
        $phone = new WhatsAppPhoneNumber(['provider_phone_id' => '987670750675913']);

        $result = app(NexMsgOrderingProvider::class)->sendOrderTypeChoices(
            $profile, $phone, '9999999999', 'Choose fulfilment', ['pickup', 'invalid', 'delivery'],
        );

        $this->assertSame('wamid-choice', $result['provider_message_id']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api-nexmsg.myteknoland.com/api/catalog/order-type'
            && $request->hasHeader('authkey', 'secret-auth-key')
            && $request->data()['choices'] === ['delivery', 'pickup']);
    }

    public function test_nexmsg_location_request_uses_native_location_endpoint(): void
    {
        config(['whatsappcenter.nexmsg.location_request_send_url' => 'https://api-nexmsg.myteknoland.com/api/catalog/location-request']);
        Http::fake(['api-nexmsg.myteknoland.com/*' => Http::response(['success' => true, 'messageId' => 'wamid-location'])]);
        $profile = new WhatsAppProviderProfile;
        $profile->credentials = ['auth_key' => 'secret-auth-key'];
        $phone = new WhatsAppPhoneNumber(['provider_phone_id' => '987670750675913']);

        $result = app(NexMsgOrderingProvider::class)->sendLocationRequest(
            $profile, $phone, '9999999999', 'Share your delivery location',
        );

        $this->assertSame('wamid-location', $result['provider_message_id']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api-nexmsg.myteknoland.com/api/catalog/location-request'
            && $request->hasHeader('authkey', 'secret-auth-key')
            && $request->data() === ['wabaId' => '987670750675913', 'to' => '919999999999', 'bodyText' => 'Share your delivery location']);
    }

    public function test_nexmsg_address_actions_are_bounded(): void
    {
        config(['whatsappcenter.nexmsg.address_actions_send_url' => 'https://api-nexmsg.myteknoland.com/api/catalog/address-actions']);
        Http::fake(['api-nexmsg.myteknoland.com/*' => Http::response(['success' => true, 'messageId' => 'wamid-address'])]);
        $profile = new WhatsAppProviderProfile;
        $profile->credentials = ['auth_key' => 'secret-auth-key'];
        $phone = new WhatsAppPhoneNumber(['provider_phone_id' => '987670750675913']);
        $result = app(NexMsgOrderingProvider::class)->sendAddressActions($profile, $phone, '9999999999', 'Confirm address', ['change', 'invalid', 'confirm']);
        $this->assertSame('wamid-address', $result['provider_message_id']);
        Http::assertSent(fn ($request) => $request->data()['actions'] === ['confirm', 'change']);
    }

    public function test_nexmsg_ordering_text_uses_owned_account_credentials(): void
    {
        config(['whatsappcenter.nexmsg.text_send_url' => 'https://api-nexmsg.myteknoland.com/api/send/text']);
        Http::fake(['api-nexmsg.myteknoland.com/*' => Http::response(['success' => true, 'messageId' => 'wamid-text'])]);
        $profile = new WhatsAppProviderProfile;
        $profile->credentials = ['auth_key' => 'secret-auth-key', 'account_id' => 'aaaaaaaaaaaaaaaaaaaaaaaa'];
        $phone = new WhatsAppPhoneNumber(['provider_phone_id' => '987670750675913']);

        $result = app(NexMsgOrderingProvider::class)->sendText($profile, $phone, '9999999999', 'Order confirmed');

        $this->assertSame('wamid-text', $result['provider_message_id']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api-nexmsg.myteknoland.com/api/send/text'
            && $request->hasHeader('authkey', 'secret-auth-key')
            && $request->data() === ['accountId' => 'aaaaaaaaaaaaaaaaaaaaaaaa', 'to' => '919999999999', 'text' => 'Order confirmed']);
    }

    public function test_nexmsg_order_button_uses_approved_template_and_token_suffix(): void
    {
        \Illuminate\Support\Facades\Cache::forget('whatsapp:order-button-approved:aaaaaaaaaaaaaaaaaaaaaaaa');
        \Illuminate\Support\Facades\Cache::forget('whatsapp:order-button-template:aaaaaaaaaaaaaaaaaaaaaaaa:cancel');
        \Illuminate\Support\Facades\Cache::forget('whatsapp:order-button-prepaid:aaaaaaaaaaaaaaaaaaaaaaaa');
        Http::fake(function ($request) {
            if ($request->url() === 'https://api-nexmsg.myteknoland.com/api/accounts') {
                return Http::response([['id' => 'aaaaaaaaaaaaaaaaaaaaaaaa', 'prepaidBalance' => 25.0]]);
            }
            if ($request->url() === 'https://api-nexmsg.myteknoland.com/api/templates') {
                return Http::response([[
                    'name' => 'nexdine_order_received_actions_v2',
                    'accountId' => 'aaaaaaaaaaaaaaaaaaaaaaaa',
                    'status' => 'APPROVED',
                ]]);
            }

            return Http::response(['success' => true, 'messageId' => 'wamid-button']);
        });
        $profile = new WhatsAppProviderProfile;
        $profile->credentials = ['auth_key' => 'secret-auth-key', 'account_id' => 'aaaaaaaaaaaaaaaaaaaaaaaa'];
        $phone = new WhatsAppPhoneNumber(['provider_phone_id' => '987670750675913']);

        $result = app(NexMsgOrderingProvider::class)->sendOrderReceiptButton($profile, $phone, '9999999999', [
            'customer_name' => 'Customer', 'order_reference' => 'ORD-TEST',
            'restaurant_name' => 'Restaurant', 'total' => 'INR 249.00',
        ], 'temporary-token');

        $this->assertSame('wamid-button', $result['provider_message_id']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api-nexmsg.myteknoland.com/api/send/template'
            && data_get($request->data(), 'components.0.parameters.1.text') === 'ORD-TEST'
            && data_get($request->data(), 'components.1.parameters.0.text') === 'ORD-TEST'
            && data_get($request->data(), 'components.2.sub_type') === 'quick_reply'
            && data_get($request->data(), 'components.2.parameters.0.payload') === 'cancel_order:temporary-token');
    }

    public function test_nexmsg_payment_receipt_prefers_combined_pay_and_cancel_buttons(): void
    {
        Cache::forget('whatsapp:order-button-template:eeeeeeeeeeeeeeeeeeeeeeee:payment');
        Cache::forget('whatsapp:order-button-prepaid:eeeeeeeeeeeeeeeeeeeeeeee');
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/api/accounts')) {
                return Http::response([['id' => 'eeeeeeeeeeeeeeeeeeeeeeee', 'prepaidBalance' => 25]]);
            }
            if (str_ends_with($request->url(), '/api/templates')) {
                return Http::response([
                    ['name' => 'nexdine_order_payment_v2', 'accountId' => 'eeeeeeeeeeeeeeeeeeeeeeee', 'status' => 'APPROVED'],
                    ['name' => 'nexdine_order_payment_cancel_chat_v3', 'accountId' => 'eeeeeeeeeeeeeeeeeeeeeeee', 'status' => 'APPROVED'],
                ]);
            }

            return Http::response(['success' => true, 'messageId' => 'wamid-combined-buttons']);
        });
        $profile = new WhatsAppProviderProfile;
        $profile->credentials = ['auth_key' => 'secret-auth-key', 'account_id' => 'eeeeeeeeeeeeeeeeeeeeeeee'];
        $phone = new WhatsAppPhoneNumber(['provider_phone_id' => '987670750675913']);

        $result = app(NexMsgOrderingProvider::class)->sendOrderReceiptButton($profile, $phone, '9999999999', [
            'customer_name' => 'Customer', 'order_reference' => 'ORD-PAYMENT-CANCEL',
            'restaurant_name' => 'Restaurant', 'total' => 'INR 249.00',
            'payment_required' => true, 'payment_token' => 'secure-payment-token',
        ], 'secure-cancel-token');

        $this->assertSame('wamid-combined-buttons', $result['provider_message_id']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/send/template')
            && $request['templateName'] === 'nexdine_order_payment_cancel_chat_v3'
            && data_get($request->data(), 'components.1.parameters.0.text') === 'secure-payment-token'
            && data_get($request->data(), 'components.2.sub_type') === 'quick_reply'
            && data_get($request->data(), 'components.2.parameters.0.payload') === 'cancel_order:secure-cancel-token'
            && count($request['components']) === 3);
    }

    public function test_nexmsg_payment_fallback_adds_approved_track_and_cancel_actions(): void
    {
        Cache::forget('whatsapp:order-button-template:dddddddddddddddddddddddd:payment');
        Cache::forget('whatsapp:order-button-prepaid:dddddddddddddddddddddddd');
        Cache::forget('whatsapp:order-button-template:dddddddddddddddddddddddd:payment-cancel-companion');
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/api/accounts')) {
                return Http::response([['id' => 'dddddddddddddddddddddddd', 'prepaidBalance' => 25]]);
            }
            if (str_ends_with($request->url(), '/api/templates')) {
                return Http::response([[
                    'name' => 'nexdine_order_payment_v2',
                    'accountId' => 'dddddddddddddddddddddddd',
                    'status' => 'APPROVED',
                ], [
                    'name' => 'nexdine_order_received_actions_v2',
                    'accountId' => 'dddddddddddddddddddddddd',
                    'status' => 'APPROVED',
                ]]);
            }

            return Http::response(['success' => true, 'messageId' => 'wamid-payment-buttons']);
        });
        $profile = new WhatsAppProviderProfile;
        $profile->credentials = ['auth_key' => 'secret-auth-key', 'account_id' => 'dddddddddddddddddddddddd'];
        $phone = new WhatsAppPhoneNumber(['provider_phone_id' => '987670750675913']);

        $result = app(NexMsgOrderingProvider::class)->sendOrderReceiptButton($profile, $phone, '9999999999', [
            'customer_name' => 'Customer', 'order_reference' => 'ORD-PAYMENT',
            'restaurant_name' => 'Restaurant', 'total' => 'INR 249.00',
            'payment_required' => true, 'payment_token' => 'secure-payment-token',
        ], 'secure-cancel-token');

        $this->assertSame('wamid-payment-buttons', $result['provider_message_id']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/send/template')
            && $request['templateName'] === 'nexdine_order_payment_v2'
            && data_get($request->data(), 'components.1.parameters.0.text') === 'secure-payment-token'
            && count($request['components']) === 2);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/send/template')
            && $request['templateName'] === 'nexdine_order_received_actions_v2'
            && data_get($request->data(), 'components.1.parameters.0.text') === 'ORD-PAYMENT'
            && data_get($request->data(), 'components.2.parameters.0.payload') === 'cancel_order:secure-cancel-token');
        Http::assertSentCount(5);
    }

    public function test_nexmsg_order_button_skips_send_when_number_has_no_prepaid_balance(): void
    {
        \Illuminate\Support\Facades\Cache::forget('whatsapp:order-button-prepaid:bbbbbbbbbbbbbbbbbbbbbbbb');
        Http::fake(['api-nexmsg.myteknoland.com/api/accounts' => Http::response([
            ['id' => 'bbbbbbbbbbbbbbbbbbbbbbbb', 'prepaidBalance' => 0],
        ])]);
        $profile = new WhatsAppProviderProfile;
        $profile->credentials = ['auth_key' => 'secret-auth-key', 'account_id' => 'bbbbbbbbbbbbbbbbbbbbbbbb'];
        $phone = new WhatsAppPhoneNumber(['provider_phone_id' => '987670750675913']);

        $this->assertNull(app(NexMsgOrderingProvider::class)->sendOrderReceiptButton(
            $profile, $phone, '9999999999', ['customer_name' => 'Test'], 'temporary-token',
        ));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/send/template'));
    }

    public function test_nexmsg_order_button_never_uses_a_pending_template(): void
    {
        Cache::forget('whatsapp:order-button-template:cccccccccccccccccccccccc:cancel');
        Cache::forget('whatsapp:order-button-prepaid:cccccccccccccccccccccccc');
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/api/accounts')) {
                return Http::response([['id' => 'cccccccccccccccccccccccc', 'prepaidBalance' => 25]]);
            }
            if (str_ends_with($request->url(), '/api/templates')) {
                return Http::response([[
                    'name' => 'nexdine_order_received_actions_v2',
                    'accountId' => 'cccccccccccccccccccccccc',
                    'status' => 'PENDING',
                ]]);
            }

            return Http::response(['success' => true, 'messageId' => 'must-not-send']);
        });
        $profile = new WhatsAppProviderProfile;
        $profile->credentials = ['auth_key' => 'secret-auth-key', 'account_id' => 'cccccccccccccccccccccccc'];
        $phone = new WhatsAppPhoneNumber(['provider_phone_id' => '987670750675913']);

        $this->assertNull(app(NexMsgOrderingProvider::class)->sendOrderReceiptButton(
            $profile, $phone, '9999999999', ['customer_name' => 'Test'], 'temporary-token',
        ));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/send/template'));
    }
}
