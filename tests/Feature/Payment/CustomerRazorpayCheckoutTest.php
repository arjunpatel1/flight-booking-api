<?php

namespace Tests\Feature\Payment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Menu\Models\OnlineMenu;
use Modules\Notification\Jobs\SendWhatsAppMessageJob;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Events\OrderPaid;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Listeners\SendOrderWhatsAppNotification;
use Modules\Order\Support\CustomerTrackingToken;
use Modules\Payment\Models\TenantPaymentGatewayConfig;
use Modules\Payment\Services\RazorpayCheckoutService;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Support\TenantContext;
use Modules\User\Models\Permission;
use Modules\User\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

class CustomerRazorpayCheckoutTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    private Tenant $tenant;

    private $branch;

    private User $customer;

    private TenantPaymentGatewayConfig $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broadcasting.default' => 'null']);
        config(['payment.gateways.razorpay' => [
            'base_url' => 'https://api.razorpay.com/v1',
            'key_id' => 'rzp_test_platform',
            'key_secret' => 'platform-secret',
            'webhook_secret' => 'platform-webhook-secret',
            'partner_auth_enabled' => true,
        ]]);
        Event::fake([OrderCreated::class, OrderPaid::class]);
        $this->setUpAggregatorTestSupport();
        $this->tenant = Tenant::query()->withoutGlobalScopes()->create([
            'name' => 'Razorpay Test Tenant',
            'slug' => 'razorpay-test-'.Str::lower(Str::random(8)),
            'domain' => 'razorpay-'.Str::lower(Str::random(8)).'.example.test',
            'is_active' => true,
        ]);
        app(TenantContext::class)->set($this->tenant);
        $this->branch = $this->makeBranch(['tenant_id' => $this->tenant->id, 'currency' => 'INR']);
        setting(['customer_payment_razorpay_enabled' => true]);
        $this->customer = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->gateway = TenantPaymentGatewayConfig::query()->create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'razorpay',
            'enabled' => true,
            'test_mode' => true,
            'credentials' => [
                'linked_account_id' => 'acc_TestRestaurant1234',
            ],
        ]);
    }

    public function test_server_creates_one_reusable_gateway_order_for_an_unpaid_order(): void
    {
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_customer_1'], 200)]);
        $order = $this->makeOrder($this->branch, ['customer_id' => $this->customer->id, 'due_amount' => 250, 'total' => 250, 'subtotal' => 250]);
        $service = app(RazorpayCheckoutService::class);

        $first = $service->create($this->tenant->id, $this->customer->id, $order->id, (string) Str::uuid());
        $second = $service->create($this->tenant->id, $this->customer->id, $order->id, (string) Str::uuid());

        $this->assertSame($first->id, $second->id);
        $this->assertSame('order_customer_1', $first->provider_payment_id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['amount'] === 25000
            && $request['currency'] === 'INR'
            && $request['receipt'] === $first->reference
            && $request['partial_payment'] === false
            && ! $request->hasHeader('X-Razorpay-Account'));
    }

    public function test_captured_payment_is_verified_and_settled_exactly_once(): void
    {
        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response(['id' => 'order_customer_2'], 200),
            'api.razorpay.com/v1/payments/pay_customer_2' => Http::response([
                'id' => 'pay_customer_2', 'order_id' => 'order_customer_2', 'amount' => 17500,
                'currency' => 'INR', 'status' => 'captured', 'method' => 'upi',
            ], 200),
        ]);
        $order = $this->makeOrder($this->branch, [
            'customer_id' => $this->customer->id, 'status' => OrderStatus::Pending,
            'kitchen_display' => false, 'due_amount' => 175, 'total' => 175, 'subtotal' => 175,
        ]);
        $service = app(RazorpayCheckoutService::class);
        $session = $service->create($this->tenant->id, $this->customer->id, $order->id, (string) Str::uuid());
        $payload = [
            'razorpay_payment_id' => 'pay_customer_2',
            'razorpay_order_id' => 'order_customer_2',
            'razorpay_signature' => hash_hmac('sha256', 'order_customer_2|pay_customer_2', 'platform-secret'),
        ];

        $service->verify($session, $payload);
        $service->verify($session->refresh(), $payload);

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'gateway' => 'razorpay', 'gateway_transaction_id' => 'pay_customer_2']);
        $order->refresh();
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertTrue((bool) $order->kitchen_display);
    }

    public function test_signed_captured_webhook_recovers_closed_browser_without_duplicate_payment(): void
    {
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_webhook_1'], 200)]);
        $order = $this->makeOrder($this->branch, [
            'customer_id' => $this->customer->id, 'status' => OrderStatus::Pending,
            'kitchen_display' => false, 'due_amount' => 99, 'total' => 99, 'subtotal' => 99,
        ]);
        $service = app(RazorpayCheckoutService::class);
        $service->create($this->tenant->id, $this->customer->id, $order->id, (string) Str::uuid());
        $payload = ['account_id' => 'acc_TestRestaurant1234', 'event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
            'id' => 'pay_webhook_1', 'order_id' => 'order_webhook_1', 'amount' => 9900,
            'currency' => 'INR', 'status' => 'captured', 'method' => 'card',
        ]]]];

        $service->processPartnerWebhook($payload);
        $service->processPartnerWebhook($payload);

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
    }

    public function test_browser_verification_rejects_a_signature_not_bound_to_the_stored_order(): void
    {
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_secure_1'], 200)]);
        $order = $this->makeOrder($this->branch, ['customer_id' => $this->customer->id, 'due_amount' => 50, 'total' => 50, 'subtotal' => 50]);
        $session = app(RazorpayCheckoutService::class)->create(
            $this->tenant->id, $this->customer->id, $order->id, (string) Str::uuid()
        );

        try {
            app(RazorpayCheckoutService::class)->verify($session, [
                'razorpay_payment_id' => 'pay_attacker',
                'razorpay_order_id' => 'order_secure_1',
                'razorpay_signature' => str_repeat('0', 64),
            ]);
            $this->fail('Invalid signature was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        Http::assertSentCount(1);
        $this->assertDatabaseMissing('payments', ['order_id' => $order->id]);
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
    }

    public function test_webhook_rejects_amount_mismatch_without_settling_order(): void
    {
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_secure_2'], 200)]);
        $order = $this->makeOrder($this->branch, ['customer_id' => $this->customer->id, 'due_amount' => 80, 'total' => 80, 'subtotal' => 80]);
        app(RazorpayCheckoutService::class)->create($this->tenant->id, $this->customer->id, $order->id, (string) Str::uuid());
        $payload = ['account_id' => 'acc_TestRestaurant1234', 'event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
            'id' => 'pay_secure_2', 'order_id' => 'order_secure_2', 'amount' => 100,
            'currency' => 'INR', 'status' => 'captured',
        ]]]];

        try {
            app(RazorpayCheckoutService::class)->processPartnerWebhook($payload);
            $this->fail('Mismatched webhook was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertDatabaseMissing('payments', ['order_id' => $order->id]);
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
    }

    public function test_customer_order_whatsapp_tracking_link_is_tenant_scoped_and_signed(): void
    {
        Queue::fake();
        setting([
            'whatsapp_enabled' => true,
            'customer_order_created_notification_enabled' => true,
            'whatsapp_templates' => [[
                'id' => 'order-submitted-template', 'event' => 'order_submitted', 'is_active' => true,
            ]],
        ]);
        app(\Modules\Setting\Services\Setting\SettingServiceInterface::class)->refreshSettingBinding();
        $menu = $this->makeMenu($this->branch);
        OnlineMenu::query()->create([
            'name' => 'Secure menu', 'slug' => 'secure-menu',
            'branch_id' => $this->branch->id, 'menu_id' => $menu->id, 'is_active' => true,
        ]);
        $this->customer->forceFill(['phone_verified_at' => now(), 'phone' => '+919999999999'])->save();
        $order = $this->makeOrder($this->branch, ['customer_id' => $this->customer->id])->load('branch');

        app(SendOrderWhatsAppNotification::class)->handle(new OrderCreated($order));

        Queue::assertPushed(SendWhatsAppMessageJob::class, function (SendWhatsAppMessageJob $job) use ($order): bool {
            $link = (string) ($job->parameters['tracking_link'] ?? '');
            parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

            return str_starts_with($link, 'https://'.$this->tenant->domain.'/online-menu/secure-menu/orders/'.$order->reference_no)
                && app(CustomerTrackingToken::class)->validate((string) ($query['tracking_token'] ?? ''), (string) $order->reference_no) === $this->tenant->id;
        });
    }

    public function test_paid_bill_whatsapp_is_queued_once_with_tenant_context_and_greeting(): void
    {
        Queue::fake();
        setting([
            'whatsapp_enabled' => true,
            'notifications_whatsapp_enabled' => true,
            'customer_payment_whatsapp_bill_enabled' => true,
            'customer_payment_whatsapp_greeting' => 'Thank you from our kitchen!',
            'whatsapp_templates' => [[
                'id' => 'billing-template', 'event' => 'billing_sent', 'is_active' => true,
            ]],
        ]);
        app(\Modules\Setting\Services\Setting\SettingServiceInterface::class)->refreshSettingBinding();
        $this->customer->forceFill(['phone_verified_at' => now()])->save();
        $order = $this->makeOrder($this->branch, [
            'customer_id' => $this->customer->id, 'due_amount' => 0,
            'payment_status' => OrderPaymentStatus::Paid,
        ])->load('branch');
        // Reproduce order-list hydration: the relation is already loaded but
        // does not contain the verification/phone columns.
        $order->setRelation('customer', User::query()->select('id', 'name')->findOrFail($this->customer->id));
        $listener = app(SendOrderWhatsAppNotification::class);

        $listener->handle(new OrderPaid($order));
        $listener->handle(new OrderPaid($order));

        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
        Queue::assertPushed(SendWhatsAppMessageJob::class, function (SendWhatsAppMessageJob $job): bool {
            return $job->tenantId === $this->tenant->id
                && $job->branchId === $this->branch->id
                && $job->template === 'billing-template'
                && ($job->parameters['greeting'] ?? null) === 'Thank you from our kitchen!'
                && ($job->metadata['campaign_id'] ?? null) !== null;
        });
    }

    public function test_cancelled_order_whatsapp_is_queued_once_with_a_human_reason(): void
    {
        Queue::fake();
        setting([
            'whatsapp_enabled' => true,
            'whatsapp_delivery_alerts_enabled' => true,
            'whatsapp_templates' => [[
                'id' => 'cancelled-template', 'event' => 'cancelled', 'is_active' => true,
            ]],
        ]);
        app(\Modules\Setting\Services\Setting\SettingServiceInterface::class)->refreshSettingBinding();
        $this->customer->forceFill(['phone_verified_at' => now(), 'phone' => '+919999999999'])->save();
        $order = $this->makeOrder($this->branch, [
            'customer_id' => $this->customer->id,
            'status' => OrderStatus::Cancelled,
        ])->load('branch');
        $order->setRelation('customer', User::query()->select('id', 'name')->findOrFail($this->customer->id));
        $listener = app(SendOrderWhatsAppNotification::class);
        $event = new OrderUpdateStatus(
            order: $order,
            status: OrderStatus::Cancelled,
            changedById: $this->customer->id,
            note: 'CUSTOMER_CANCELLED',
        );

        $listener->handle($event);
        $listener->handle($event);

        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
        Queue::assertPushed(SendWhatsAppMessageJob::class, fn (SendWhatsAppMessageJob $job): bool => $job->template === 'cancelled-template'
            && ($job->parameters['reason'] ?? null) === 'Cancelled by customer'
        );
    }

    public function test_disabled_order_cancelled_notification_switch_suppresses_customer_message(): void
    {
        Queue::fake();
        setting(['whatsapp_enabled' => true, 'customer_order_cancelled_notification_enabled' => false,
            'whatsapp_templates' => [['id' => 'cancelled-template', 'event' => 'cancelled', 'is_active' => true]]]);
        app(\Modules\Setting\Services\Setting\SettingServiceInterface::class)->refreshSettingBinding();
        $this->customer->forceFill(['phone_verified_at' => now(), 'phone' => '+919999999999'])->save();
        $order = $this->makeOrder($this->branch, ['customer_id' => $this->customer->id,
            'status' => OrderStatus::Cancelled])->load('branch');

        app(SendOrderWhatsAppNotification::class)->handle(new OrderUpdateStatus(
            order: $order, status: OrderStatus::Cancelled, note: 'CUSTOMER_CANCELLED',
        ));

        Queue::assertNothingPushed();
    }

    public function test_whatsapp_catalog_cancellation_uses_the_approved_template(): void
    {
        Queue::fake();
        setting(['whatsapp_templates' => [[
            'id' => 'catalog-cancelled-template', 'event' => 'cancelled', 'is_active' => true,
        ]]]);
        app(\Modules\Setting\Services\Setting\SettingServiceInterface::class)->refreshSettingBinding();
        $order = $this->makeOrder($this->branch, ['customer_id' => $this->customer->id, 'status' => OrderStatus::Cancelled])
            ->load('customer', 'branch');
        $profile = \Modules\WhatsAppCenter\Models\WhatsAppProviderProfile::query()->withoutGlobalTenant()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Catalog', 'ownership_mode' => 'restaurant_owned',
            'provider' => 'nexmsg', 'credentials' => ['auth_key' => 'test', 'account_id' => '0123456789abcdef01234567'],
            'status' => 'connected', 'is_active' => true,
        ]);
        $number = $profile->phoneNumbers()->create([
            'provider_phone_id' => 'catalog-number', 'display_number' => '+919999999999', 'status' => 'connected', 'is_active' => true,
        ]);
        $assignment = \Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment::query()->withoutGlobalTenant()->create([
            'tenant_id' => $this->tenant->id, 'provider_profile_id' => $profile->id, 'phone_number_id' => $number->id,
            'ownership_mode' => 'restaurant_owned', 'allowed_branch_ids' => [$this->branch->id], 'capabilities' => ['ordering' => true], 'is_active' => true,
        ]);
        $conversation = \Modules\WhatsAppCenter\Models\WhatsAppConversation::query()->withoutGlobalTenant()->create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'assignment_id' => $assignment->id, 'customer_phone' => '+919999999999', 'customer_name' => 'Customer', 'state' => 'bot',
        ]);
        \Modules\WhatsAppCenter\Models\WhatsAppOrderSession::query()->withoutGlobalTenant()->create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'conversation_id' => $conversation->id, 'cart_uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'order_type' => 'delivery', 'state' => 'cancelled',
        ]);

        app(SendOrderWhatsAppNotification::class)->handle(new OrderUpdateStatus(
            order: $order, status: OrderStatus::Cancelled, note: 'CUSTOMER_CANCELLED',
        ));

        Queue::assertPushed(SendWhatsAppMessageJob::class, fn (SendWhatsAppMessageJob $job): bool => $job->template === 'catalog-cancelled-template'
            && $job->recipient === '+919999999999'
            && ($job->parameters['reason'] ?? null) === 'Cancelled by customer'
            && ($job->metadata['audience'] ?? null) === 'whatsapp_order_status'
        );
    }

    public function test_authorized_tenant_admin_controls_customer_payment_collection_methods(): void
    {
        setting([
            'whatsapp_enabled' => true,
            'whatsapp_templates' => [[
                'id' => 'billing-template', 'event' => 'billing_sent', 'is_active' => true,
            ]],
        ]);
        $admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        Permission::findOrCreate('admin.settings.edit', 'api');
        $admin->givePermissionTo('admin.settings.edit');
        Sanctum::actingAs($admin, ['*'], 'api');

        $this->putJson('/api/v1/payment-gateway-settings/checkout/options', [
            'cash_on_delivery' => true,
            'pay_at_counter' => true,
            'razorpay' => true,
            'whatsapp_bill' => true,
            'whatsapp_greeting' => 'Welcome and thank you.',
        ])->assertOk()->assertJsonPath('body.checkout_options.cash_on_delivery', true)
            ->assertJsonPath('body.checkout_options.pay_at_counter', true)
            ->assertJsonPath('body.checkout_options.razorpay', true)
            ->assertJsonPath('body.checkout_options.whatsapp_bill', true);

        $this->assertTrue((bool) setting('customer_payment_cod_enabled'));
        $this->assertTrue((bool) setting('customer_payment_counter_enabled'));
        $this->assertTrue((bool) setting('customer_payment_razorpay_enabled'));
        $this->assertTrue((bool) setting('customer_payment_whatsapp_bill_enabled'));
    }

    public function test_manual_payment_without_optional_super_admin_role_does_not_crash(): void
    {
        $order = $this->makeOrder($this->branch, ['customer_id' => $this->customer->id, 'due_amount' => 100]);
        $request = \Illuminate\Http\Request::create('/manual-requests', 'POST', ['order_reference' => $order->reference_no]);
        $request->attributes->set('tenant_id', $this->tenant->id);
        $request->setUserResolver(fn () => $this->customer);
        // With no eligible staff this is a recoverable conflict, never a 500.
        try {
            app(\Modules\Payment\Http\Controllers\Api\V1\RazorpayCheckoutController::class)->requestManualPayment($request);
            $this->fail('Expected no staff conflict.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame('No active restaurant staff are available for this payment request.', $exception->getMessage());
        }
    }

    public function test_payment_options_hide_razorpay_when_branch_does_not_allow_it(): void
    {
        $this->branch->update(['payment_methods' => ['cash']]);
        setting(['customer_payment_razorpay_enabled' => true, 'customer_payment_cod_enabled' => true]);
        app(\Modules\Setting\Services\Setting\SettingServiceInterface::class)->refreshSettingBinding();
        $menu = $this->makeMenu($this->branch);

        $this->withHeaders(['Origin' => 'https://'.$this->tenant->domain, 'X-NexDine-Tenant-Domain' => $this->tenant->domain])
            ->getJson('/api/v1/customer-app/payments/options?menu_reference='.$menu->uuid)
            ->assertOk()
            ->assertJsonPath('body.razorpay', false)
            ->assertJsonPath('body.cash_on_delivery', true);
    }

    public function test_payment_options_allow_razorpay_for_branch_upi_or_card_methods(): void
    {
        $this->branch->update(['payment_methods' => ['upi', 'card']]);
        setting(['customer_payment_razorpay_enabled' => true]);
        app(\Modules\Setting\Services\Setting\SettingServiceInterface::class)->refreshSettingBinding();
        $menu = $this->makeMenu($this->branch);

        $this->withHeaders(['Origin' => 'https://'.$this->tenant->domain, 'X-NexDine-Tenant-Domain' => $this->tenant->domain])
            ->getJson('/api/v1/customer-app/payments/options?menu_reference='.$menu->uuid)
            ->assertOk()
            ->assertJsonPath('body.razorpay', true);
    }

    public function test_anonymous_browser_checkout_can_discover_payment_options_from_its_menu(): void
    {
        // The branch factory randomizes payment methods. This scenario
        // deliberately exercises counter payment, so cash must be enabled.
        $this->branch->update(['payment_methods' => ['cash']]);
        setting(['customer_payment_counter_enabled' => true]);
        app(\Modules\Setting\Services\Setting\SettingServiceInterface::class)->refreshSettingBinding();
        $menu = $this->makeMenu($this->branch);

        $this->withHeaders(['Origin' => 'https://'.$this->tenant->domain, 'X-NexDine-Tenant-Domain' => $this->tenant->domain])
            ->getJson('/api/v1/customer-app/payments/options?menu_reference='.$menu->uuid)
            ->assertOk()
            ->assertJsonPath('body.pay_at_counter', true)
            ->assertJsonMissing(['code' => 'CUSTOMER_APP_UNAVAILABLE']);
    }
}
