<?php

namespace Tests\Feature\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Branch\Models\Branch;
use Modules\Currency\Models\CurrencyRate;
use Modules\Invoice\Services\CreateInvoice\CreateInvoiceServiceInterface;
use Modules\Notification\Jobs\SendWhatsAppMessageJob;
use Modules\Order\Delivery\CustomerDeliveryQuote;
use Modules\Order\Delivery\DeliveryAddressResolver;
use Modules\Order\Delivery\DeliveryCostCalculator;
use Modules\Order\Delivery\DeliveryStateMachine;
use Modules\Order\Delivery\DeliveryWallet;
use Modules\Order\Enums\DeliveryStatus;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Events\DeliveryStatusChanged;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Events\OrderPaid;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Listeners\MarkDeliveryAfterOrderCancellation;
use Modules\Order\Listeners\SendDeliveryWhatsAppNotification;
use Modules\Order\Listeners\SettleDeliveryWalletAfterCancellation;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDelivery;
use Modules\Order\Services\OrderCreate\CreateOrderServiceInterface;
use Modules\Payment\Models\TenantPaymentGatewayConfig;
use Modules\Payment\Services\DirectUpiService;
use Modules\Payment\Services\RazorpayCheckoutService;
use Modules\Printer\app\Factories\OrderResourceFactory;
use Modules\Printer\Services\Render\EscPos\ExperimentalEscPosInvoiceTemplate;
use Modules\Product\Models\Product;
use Modules\Saas\Models\CustomerAppRegistration;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Support\TenantContext;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\CustomerAddress;
use Modules\User\Models\Permission;
use Modules\User\Models\Role;
use Modules\User\Models\User;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

/** Runs real migrations and endpoint/service writes; all external payment calls are fake. */
class DeliveryFinancialLifecycleTest extends TestCase
{
    use AggregatorTestSupport, RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    private User $customer;

    private Product $product;

    private string $token;

    private string $cart;

    private array $address = ['recipient_name' => 'Delivery Diner', 'phone' => '9876543210',
        'address_line1' => '12 Test Street', 'city' => 'Test City', 'postal_code' => '123456',
        'latitude' => 0, 'longitude' => 0.009];

    protected function setUp(): void
    {
        parent::setUp();
        config(['broadcasting.default' => 'null', 'delivery.integration_enabled' => false]);
        Queue::fake();
        Event::fake([OrderCreated::class, OrderPaid::class]);
        Http::preventStrayRequests();
        $this->setUpAggregatorTestSupport();
        $this->tenant = Tenant::query()->create(['name' => 'Delivery Tests', 'slug' => 'delivery-tests',
            'domain' => 'delivery-tests.example.test', 'is_active' => true]);
        app(TenantContext::class)->set($this->tenant);
        $plan = DB::table('subscription_plans')->insertGetId(['name' => 'Delivery Test Plan', 'code' => 'delivery-test',
            'features' => json_encode(['pos', 'online_ordering', 'customer_app', 'delivery']),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_subscriptions')->insert(['tenant_id' => $this->tenant->id, 'subscription_plan_id' => $plan,
            'status' => 'active', 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $registration = CustomerAppRegistration::query()->create(['uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id, 'package_id' => 'com.nexdine.deliverytests', 'display_name' => 'Delivery Tests',
            'platform' => 'android', 'status' => 'active', 'branding_revision' => 1]);
        $this->token = Str::random(64);
        DB::table('customer_app_sessions')->insert(['uuid' => (string) Str::uuid(),
            'customer_app_registration_id' => $registration->id, 'tenant_id' => $this->tenant->id,
            'installation_id' => (string) Str::uuid(), 'token_hash' => hash('sha256', $this->token),
            'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->branch = $this->makeBranch(['tenant_id' => $this->tenant->id, 'currency' => 'INR',
            'latitude' => 0, 'longitude' => 0, 'delivery_radius_km' => 8,
            'order_types' => ['delivery', 'takeaway'], 'payment_methods' => ['cash']]);
        CurrencyRate::query()->firstOrCreate(['currency' => 'INR'], ['rate' => 1]);
        app(DeliveryWallet::class)->adjust($this->tenant->id, 'credit', 1000, (string) Str::uuid(), 'Initial test delivery wallet funding.', null);
        setting(['default_currency' => 'INR', 'delivery_enabled' => true, 'customer_app_delivery_enabled' => true,
            'delivery_pricing_method' => 'slabs', 'delivery_charge_slabs' => [
                ['min_km' => 0, 'max_km' => 2, 'charge' => 20], ['min_km' => 2, 'max_km' => 8, 'charge' => 40]],
            'delivery_customer_fee_gst_rate' => 0,
            'online_order_kot_release_policy' => 'after_payment_or_approval']);
        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id, 'is_active' => true, 'can_login' => true]);
        Role::findOrCreate(DefaultRole::Customer->value, 'api');
        $this->customer->assignRole(DefaultRole::Customer->value);
        Sanctum::actingAs($this->customer, ['customer'], 'sanctum');
        $menu = $this->makeMenu($this->branch);
        $this->product = Product::query()->create(['menu_id' => $menu->id, 'name' => 'Test Food',
            'price' => 100, 'is_active' => true, 'is_available' => true, 'food_type' => 'veg']);
        $this->cart = (string) Str::uuid();
    }

    private function headers(array $extra = []): array
    {
        return ['X-NexDine-Customer-App-Token' => $this->token, ...$extra];
    }

    private function cart(int $qty = 1): void
    {
        $this->postJson("/api/v1/customer-app/cart/{$this->cart}/initialize",
            ['branch_id' => $this->branch->id, 'order_type' => 'delivery'], $this->headers())->assertOk();
        $this->postJson("/api/v1/customer-app/cart/{$this->cart}/items/batch", ['branch_id' => $this->branch->id,
            'items' => [['product_id' => $this->product->id, 'qty' => $qty, 'options' => []]]], $this->headers())->assertOk();
    }

    private function quote(?array $address = null)
    {
        return $this->postJson("/api/v1/customer-app/delivery/quote/{$this->cart}",
            ['branch_id' => $this->branch->id, 'delivery_address' => $address ?? $this->address], $this->headers());
    }

    private function submit(array $extra = [], ?string $key = null)
    {
        return $this->postJson("/api/v1/customer-app/orders/{$this->cart}", [
            'branch_id' => $this->branch->id, 'menu_id' => $this->product->menu_id, 'type' => 'delivery', 'customer_name' => 'Delivery Diner',
            'customer_mobile' => '9876543210', 'payment_method' => 'cash',
            'delivery_address' => $this->address, 'expected_payable_total' => 120, ...$extra],
            $this->headers(['Idempotency-Key' => $key ?? (string) Str::uuid()]));
    }

    private function order(): Order
    {
        $this->cart();
        $this->submit()->assertCreated();

        return Order::query()->latest('id')->firstOrFail();
    }

    public function test_disabled_delivery_master_switch_blocks_quote_and_order_submission(): void
    {
        setting(['delivery_enabled' => false]);
        $this->cart();

        $this->quote()
            ->assertStatus(422)
            ->assertJsonPath('message', 'Delivery checkout is disabled for this restaurant. Please choose pickup or contact the restaurant.');
        $this->submit()->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_missing_delivery_slabs_never_create_an_implicit_free_order(): void
    {
        setting(['delivery_charge_slabs' => []]);
        $this->cart();

        $this->quote()
            ->assertOk()
            ->assertJsonPath('body.serviceable', false)
            ->assertJsonPath('body.free_delivery', false)
            ->assertJsonPath('body.failure_code', 'DELIVERY_PRICE_UNAVAILABLE');
        $this->submit()->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_persists_one_delivery_with_pending_provider_cost(): void
    {
        $this->cart();
        $this->quote()->assertOk()->assertJsonPath('body.delivery_fee', 20)->assertJsonPath('body.payable_total', 120);
        $this->submit()->assertCreated()->assertJsonPath('body.total', 120);
        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertDatabaseHas('order_deliveries', ['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'order_id' => $order->id, 'customer_delivery_fee' => 20, 'provider_final_cost' => null, 'provider_quoted_cost' => null]);
        $delivery = $order->delivery()->firstOrFail();
        $this->assertSame(0.009, $delivery->dropoff_longitude);
        $this->assertEqualsWithDelta(1.001, $delivery->distance_km, .001);
        $this->assertSame('slab:0-2', data_get($order->fulfilmentDetails(), 'delivery_pricing_rule'));
        $this->assertEquals(120.0, $order->due_amount->amount());
        $this->assertDatabaseCount('order_deliveries', 1);
    }

    public function test_duplicate_delivery_row_is_rejected_by_database(): void
    {
        $order = $this->order();
        $this->expectException(\Illuminate\Database\QueryException::class);
        OrderDelivery::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'order_id' => $order->id]);
    }

    public function test_stale_address_cart_or_settings_total_is_rejected_without_order(): void
    {
        $this->cart();
        $this->quote()->assertOk();
        $far = [...$this->address, 'longitude' => .027];
        $this->submit(['delivery_address' => $far])->assertStatus(409);
        $this->cart(2);
        $this->submit()->assertStatus(409);
        $this->cart();
        setting(['delivery_charge_slabs' => [['min_km' => 0, 'max_km' => 8, 'charge' => 30]]]);
        $this->submit()->assertStatus(409);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_deliveries', 0);
    }

    public function test_free_delivery_threshold_is_inclusive_and_snapshot_is_zero(): void
    {
        setting(['free_delivery_above_order_amount' => 100]);
        $this->cart();
        $this->quote()->assertOk()->assertJsonPath('body.delivery_fee', 0);
        $this->submit(['expected_payable_total' => 100])->assertCreated();
        $this->assertDatabaseHas('order_deliveries', ['customer_delivery_fee' => 0, 'provider_final_cost' => null]);
        $this->assertEquals(100.0, Order::query()->latest('id')->firstOrFail()->total->amount());
    }

    public function test_provider_cost_and_settings_changes_never_change_historical_payable(): void
    {
        $order = $this->order();
        $order->delivery()->update(['provider_final_cost' => 60, 'restaurant_contribution' => 40]);
        setting(['delivery_charge_slabs' => [['min_km' => 0, 'max_km' => 8, 'charge' => 90]]]);
        $order->syncApplicableOrderTaxes();
        $order->refresh();
        $this->assertEquals(120.0, $order->total->amount());
        $this->assertEquals(120.0, $order->due_amount->amount());
        $this->assertSame(20.0, (float) $order->delivery->customer_delivery_fee);
        $this->assertSame('slab:0-2', data_get($order->fulfilmentDetails(), 'delivery_pricing_rule'));
    }

    public function test_razorpay_initialization_and_retry_use_persisted_customer_total(): void
    {
        $order = $this->order();
        setting(['customer_payment_razorpay_enabled' => true]);
        config(['payment.gateways.razorpay' => [
            'base_url' => 'https://api.razorpay.com/v1',
            'key_id' => 'rzp_test_platform',
            'key_secret' => 'platform-secret',
            'webhook_secret' => 'platform-webhook-secret',
            'partner_auth_enabled' => true,
        ]]);
        TenantPaymentGatewayConfig::query()->create(['tenant_id' => $this->tenant->id, 'provider' => 'razorpay',
            'enabled' => true, 'test_mode' => true, 'credentials' => ['linked_account_id' => 'acc_TestDelivery1234']]);
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_delivery_test'])]);
        $service = app(RazorpayCheckoutService::class);
        $first = $service->create($this->tenant->id, $this->customer->id, $order->id, (string) Str::uuid());
        $retry = $service->create($this->tenant->id, $this->customer->id, $order->id, (string) Str::uuid());
        $this->assertSame($first->id, $retry->id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['amount'] === 12000 && $request['currency'] === 'INR');
        $this->assertDatabaseCount('order_deliveries', 1);
    }

    public function test_direct_upi_uses_persisted_customer_total(): void
    {
        $order = $this->order();
        TenantPaymentGatewayConfig::query()->create(['tenant_id' => $this->tenant->id, 'provider' => 'direct_upi',
            'enabled' => true, 'test_mode' => true, 'credentials' => ['base_url' => 'https://upi.example.test',
                'api_key' => 'test-only', 'merchant_upi_account_id' => 'test-account', 'webhook_secret' => 'test-secret']]);
        Http::fake(['upi.example.test/payments' => Http::response(['payment' => ['id' => 'upi_delivery_test', 'status' => 'pending']], 201)]);
        app(DirectUpiService::class)->create($this->tenant->id, $this->customer->id, $order->id, (string) Str::uuid());
        Http::assertSent(fn ($request) => $request['amount'] === '120.00' && $request['currency'] === 'INR');
    }

    public function test_wallet_debits_total_including_fee_and_replay_does_not_debit_twice(): void
    {
        DB::table('customer_wallet_accounts')->insert(['tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'currency' => 'INR', 'balance' => 120, 'created_at' => now(), 'updated_at' => now()]);
        $this->cart();
        $key = (string) Str::uuid();
        $this->submit(['payment_method' => 'wallet'], $key)->assertCreated();
        $this->submit(['payment_method' => 'wallet'], $key)->assertCreated();
        $this->assertDatabaseHas('customer_wallet_accounts', ['customer_id' => $this->customer->id, 'balance' => 0]);
        $this->assertDatabaseCount('customer_wallet_transactions', 1);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_deliveries', 1);
        $this->assertSame(OrderPaymentStatus::Paid, Order::query()->latest('id')->firstOrFail()->payment_status);
    }

    public function test_insufficient_wallet_rolls_back_order_and_delivery(): void
    {
        DB::table('customer_wallet_accounts')->insert(['tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'currency' => 'INR', 'balance' => 119.99, 'created_at' => now(), 'updated_at' => now()]);
        $this->cart();
        $this->submit(['payment_method' => 'wallet'])->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_deliveries', 0);
        $this->assertDatabaseCount('customer_wallet_transactions', 0);
    }

    public function test_invoice_has_customer_fee_once_and_no_provider_financials(): void
    {
        $order = $this->order();
        $order->delivery()->update(['provider_final_cost' => 60, 'restaurant_contribution' => 40]);
        $invoice = app(CreateInvoiceServiceInterface::class)->create($order);
        $this->assertEquals(120.0, $invoice->refresh()->total->amount());
        $this->assertCount(2, $invoice->lines);
        $charges = $invoice->lines->filter(fn ($line) => $line->order_product_id === null);
        $this->assertCount(1, $charges);
        $this->assertEquals(20.0, $charges->first()->line_total_incl_tax->amount());
        $this->assertStringNotContainsString('provider', strtolower($invoice->lines->toJson()));
        $this->assertSame($invoice->id, app(CreateInvoiceServiceInterface::class)->create($order)->id);
    }

    public function test_html_and_escpos_bill_display_customer_fee_once(): void
    {
        $order = $this->order();
        $payload = ['order' => OrderResourceFactory::order($order), 'currency_subunit' => 2,
            'branch' => ['name' => 'Test Outlet'], 'products' => [], 'payments' => []];
        $html = view('print.templates.bill', ['payload' => $payload])->render();
        $this->assertSame(1, substr_count($html, 'Delivery Fee'));
        $receipt = json_encode(app(ExperimentalEscPosInvoiceTemplate::class)->render($payload, 48));
        $this->assertSame(1, substr_count($receipt, 'Delivery Fee'));
        $this->assertStringContainsString('120.00', $receipt);
    }

    public function test_order_quantity_edits_preserve_checkout_delivery_fee(): void
    {
        $order = $this->order();
        $line = $order->products()->firstOrFail();
        $line->update(['quantity' => 2, 'subtotal' => 200, 'total' => 200]);
        $order->recalculate();
        $this->assertEquals(220.0, $order->refresh()->total->amount());
        $line->update(['quantity' => 1, 'subtotal' => 100, 'total' => 100]);
        $order->recalculate();
        $this->assertEquals(120.0, $order->refresh()->total->amount());
        $this->assertSame(20.0, (float) $order->delivery->customer_delivery_fee);
    }

    public function test_cancel_before_assignment_retains_fee_and_marks_delivery_cancelled(): void
    {
        $order = $this->order();
        $order->update(['status' => OrderStatus::Cancelled]);
        app(MarkDeliveryAfterOrderCancellation::class)->handle(new OrderUpdateStatus($order, OrderStatus::Cancelled));
        $this->assertSame(DeliveryStatus::Cancelled, $order->delivery()->firstOrFail()->status);
        $this->assertSame(20.0, (float) $order->delivery()->firstOrFail()->customer_delivery_fee);
        Queue::assertNothingPushed();
    }

    public function test_delivery_state_change_emits_a_tenant_bound_milestone_event(): void
    {
        Event::fake([DeliveryStatusChanged::class]);
        $delivery = $this->order()->delivery()->firstOrFail();

        app(DeliveryStateMachine::class)->transition($delivery, DeliveryStatus::FetchingQuotes);

        Event::assertDispatched(DeliveryStatusChanged::class, fn (DeliveryStatusChanged $event) => $event->tenantId === $this->tenant->id
            && $event->deliveryId === $delivery->id
            && $event->from === DeliveryStatus::WaitingForAssignment
            && $event->to === DeliveryStatus::FetchingQuotes);
    }

    public function test_customer_delivery_milestone_queues_once_with_a_safe_tracking_link(): void
    {
        $order = $this->order();
        $this->customer->update(['phone' => '+919876543210', 'phone_verified_at' => now()]);
        setting([
            'whatsapp_enabled' => true,
            'whatsapp_delivery_alerts_enabled' => true,
            'frontend_url' => 'https://delivery-tests.example.test',
            'whatsapp_templates' => [[
                'id' => 'approved_delivery_update',
                'template_id' => 'approved_delivery_update',
                'event' => 'delivery_update',
                'is_active' => true,
            ]],
        ]);
        $delivery = $order->delivery()->firstOrFail();
        $delivery->update(['tracking_url' => 'javascript:alert(1)', 'status' => DeliveryStatus::RiderAssigned, 'rider_name' => 'Test Rider (OTP: 5861)', 'rider_phone' => '+919123456789']);
        $event = new DeliveryStatusChanged($this->tenant->id, $delivery->id, $order->id,
            DeliveryStatus::Booked, DeliveryStatus::RiderAssigned);

        app(SendDeliveryWhatsAppNotification::class)->handle($event);
        app(SendDeliveryWhatsAppNotification::class)->handle($event);

        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
        Queue::assertPushed(SendWhatsAppMessageJob::class, fn (SendWhatsAppMessageJob $job) => $job->tenantId === $this->tenant->id
            && $job->template === 'approved_delivery_update'
            && $job->metadata['delivery_id'] === $delivery->id
            && $job->metadata['delivery_status'] === DeliveryStatus::RiderAssigned->value
            && $job->parameters['order_id'] === $order->reference_no
            && $job->parameters['delivery_status'] === 'Rider Assigned · Rider: Test Rider · Call: +919123456789 · OTP: 5861'
            && $job->parameters['rider_name'] === 'Test Rider'
            && $job->parameters['rider_phone'] === '+919123456789'
            && $job->parameters['delivery_otp'] === '5861'
            && str_starts_with($job->parameters['tracking_link'], 'https://delivery-tests.example.test')
            && ! str_contains($job->parameters['tracking_link'], 'javascript:'));
    }

    public function test_delivered_milestone_queues_tracking_and_secure_rating_messages(): void
    {
        $order = $this->order();
        $this->customer->update(['phone' => '+919876543210', 'phone_verified_at' => now()]);
        setting([
            'whatsapp_enabled' => true,
            'whatsapp_delivery_alerts_enabled' => true,
            'frontend_url' => 'https://delivery-tests.example.test',
            'whatsapp_templates' => [[
                'id' => 'approved_delivery_update', 'template_id' => 'approved_delivery_update',
                'event' => 'delivery_update', 'is_active' => true,
            ], [
                'id' => 'approved_feedback', 'template_id' => 'feedback_request_v2',
                'event' => 'feedback_request', 'is_active' => true,
            ]],
        ]);
        $delivery = $order->delivery()->firstOrFail();
        $delivery->update(['status' => DeliveryStatus::Delivered, 'delivered_at' => now()]);

        app(SendDeliveryWhatsAppNotification::class)->handle(new DeliveryStatusChanged(
            $this->tenant->id, $delivery->id, $order->id,
            DeliveryStatus::ArrivedAtCustomer, DeliveryStatus::Delivered,
        ));

        Queue::assertPushed(SendWhatsAppMessageJob::class, 2);
        Queue::assertPushed(SendWhatsAppMessageJob::class, fn (SendWhatsAppMessageJob $job) => $job->template === 'approved_delivery_update'
            && $job->parameters['delivery_status'] === 'Delivered'
            && $job->metadata['audience'] === 'delivery_tracking');
        Queue::assertPushed(SendWhatsAppMessageJob::class, fn (SendWhatsAppMessageJob $job) => $job->template === 'feedback_request_v2'
            && $job->metadata['audience'] === 'feedback_request'
            && str_contains($job->parameters['feedback_link'], '/feedback/orders/'.$order->reference_no)
            && str_contains($job->parameters['feedback_link'], 'token='));
    }

    public function test_disabled_rider_assignment_notification_switch_suppresses_customer_message(): void
    {
        $order = $this->order();
        $delivery = $order->delivery()->firstOrFail();
        $delivery->update(['status' => DeliveryStatus::RiderAssigned]);
        $this->customer->update(['phone' => '+919876543210', 'phone_verified_at' => now()]);
        setting(['whatsapp_enabled' => true, 'whatsapp_delivery_alerts_enabled' => true,
            'customer_delivery_assigned_notification_enabled' => false]);

        app(SendDeliveryWhatsAppNotification::class)->handle(new DeliveryStatusChanged(
            $this->tenant->id, $delivery->id, $order->id,
            DeliveryStatus::Booked, DeliveryStatus::RiderAssigned,
        ));

        Queue::assertNothingPushed();
    }

    public function test_internal_delivery_state_does_not_notify_customer(): void
    {
        $order = $this->order();
        app(SendDeliveryWhatsAppNotification::class)->handle(new DeliveryStatusChanged(
            $this->tenant->id, $order->delivery()->firstOrFail()->id, $order->id,
            DeliveryStatus::WaitingForAssignment, DeliveryStatus::FetchingQuotes,
        ));

        Queue::assertNothingPushed();
    }

    public function test_disabled_delivery_template_does_not_fall_back_to_stock_template(): void
    {
        $order = $this->order();
        $delivery = $order->delivery()->firstOrFail();
        $delivery->update(['status' => DeliveryStatus::RiderAssigned]);
        $this->customer->update(['phone' => '+919876543210', 'phone_verified_at' => now()]);
        setting([
            'whatsapp_enabled' => true,
            'whatsapp_delivery_alerts_enabled' => true,
            'whatsapp_templates' => [[
                'id' => 'address_delivery_update',
                'event' => 'delivery_update',
                'is_active' => false,
            ]],
        ]);

        app(SendDeliveryWhatsAppNotification::class)->handle(new DeliveryStatusChanged(
            $this->tenant->id, $delivery->id, $order->id,
            DeliveryStatus::Booked, DeliveryStatus::RiderAssigned,
        ));

        Queue::assertNothingPushed();
    }

    public function test_delayed_delivery_milestone_cannot_notify_after_delivery_has_advanced(): void
    {
        $order = $this->order();
        $this->customer->update(['phone' => '+919876543210', 'phone_verified_at' => now()]);
        setting(['whatsapp_enabled' => true, 'whatsapp_delivery_alerts_enabled' => true]);
        $delivery = $order->delivery()->firstOrFail();
        $delivery->update(['status' => DeliveryStatus::Delivered]);

        app(SendDeliveryWhatsAppNotification::class)->handle(new DeliveryStatusChanged(
            $this->tenant->id, $delivery->id, $order->id,
            DeliveryStatus::Booked, DeliveryStatus::RiderAssigned,
        ));

        Queue::assertNothingPushed();
    }

    public function test_external_booking_cancellation_is_recoverable_manual_review(): void
    {
        $order = $this->order();
        $order->delivery()->update(['external_delivery_id' => 'test-booking', 'status' => DeliveryStatus::RiderSearching]);
        app(MarkDeliveryAfterOrderCancellation::class)->handle(new OrderUpdateStatus($order, OrderStatus::Cancelled));
        $delivery = $order->delivery()->firstOrFail();
        $this->assertSame(DeliveryStatus::ManualReviewRequired, $delivery->status);
        $this->assertSame('external_cancellation_required', $delivery->assignment_status);
        $this->assertSame('test-booking', $delivery->external_delivery_id);
    }

    public function test_refund_event_preserves_provider_confirmed_delivery_cancellation(): void
    {
        $order = $this->order();
        $order->delivery()->update([
            'external_delivery_id' => 'cancelled-test-booking',
            'status' => DeliveryStatus::Cancelled,
            'assignment_status' => 'cancelled',
            'provider_status' => 'CANCELLED',
            'cancelled_at' => now(),
        ]);

        app(MarkDeliveryAfterOrderCancellation::class)->handle(
            new OrderUpdateStatus($order, OrderStatus::Refunded)
        );

        $delivery = $order->delivery()->firstOrFail();
        $this->assertSame(DeliveryStatus::Cancelled, $delivery->status);
        $this->assertSame('cancelled', $delivery->assignment_status);
        $this->assertSame('CANCELLED', $delivery->provider_status);
    }

    public function test_saved_address_cannot_be_resolved_by_another_customer_or_tenant(): void
    {
        $reference = (string) Str::uuid();
        CustomerAddress::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->customer->id,
            'client_reference' => $reference, ...$this->address]);
        $other = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(DeliveryAddressResolver::class)->resolve(['id' => $reference], $other, $this->tenant->id);
    }

    public function test_quote_rejects_another_tenants_branch(): void
    {
        $this->cart();
        $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other', 'domain' => 'other.example.test', 'is_active' => true]);
        $branch = $this->makeBranch(['tenant_id' => $other->id]);
        $this->postJson("/api/v1/customer-app/delivery/quote/{$this->cart}",
            ['branch_id' => $branch->id, 'delivery_address' => $this->address], $this->headers())->assertForbidden();
    }

    public function test_fee_above_subtotal_customer_checkout_and_generic_safeguard_are_explicit(): void
    {
        $this->product->update(['price' => 20]);
        setting(['delivery_charge_slabs' => [['min_km' => 0, 'max_km' => 8, 'charge' => 40]]]);
        $this->cart();
        $this->submit(['expected_payable_total' => 60])->assertCreated();
        $this->assertEquals(60.0, Order::query()->latest('id')->firstOrFail()->total->amount());
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(CreateOrderServiceInterface::class)->createForActor(['branch_id' => $this->branch->id,
            'type' => 'delivery', 'products' => [['id' => $this->product->id, 'quantity' => 1, 'options' => []]],
            'additional_payments' => ['customer_delivery_fee' => 40]], $this->customer);
    }

    public function test_trusted_whatsapp_delivery_fee_can_exceed_small_item_subtotal(): void
    {
        $this->product->update(['price' => 20]);
        $order = app(CreateOrderServiceInterface::class)->createForActor([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'type' => 'delivery',
            'products' => [['id' => $this->product->id, 'quantity' => 1, 'options' => []]],
            'payment_methods' => [],
            'payments' => [],
            'additional_payments' => ['customer_delivery_fee' => 40],
            'fulfilment' => ['source' => 'whatsapp', 'customer_delivery_fee' => 40],
        ], $this->customer, trustedServerCharges: true);

        $this->assertEquals(20.0, $order->subtotal->amount());
        $this->assertEquals(60.0, $order->total->amount());
    }

    public function test_contribution_matches_existing_split_policy(): void
    {
        $this->assertSame(['restaurant_contribution' => 15.0, 'platform_contribution' => 0.0, 'delivery_margin' => 0.0],
            app(DeliveryCostCalculator::class)->split(40, 55));
        $this->assertSame(['restaurant_contribution' => 5.0, 'platform_contribution' => 10.0, 'delivery_margin' => 0.0],
            app(DeliveryCostCalculator::class)->split(40, 55, 10));
    }

    public function test_delivery_row_cannot_be_created_with_wrong_order_ownership(): void
    {
        $order = $this->order();
        $other = Tenant::query()->create(['name' => 'Other Tenant', 'slug' => 'ownership-other', 'is_active' => true]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        OrderDelivery::query()->create(['tenant_id' => $other->id, 'branch_id' => $this->branch->id, 'order_id' => $order->id]);
    }

    public function test_delivery_records_are_hidden_from_other_tenant_users(): void
    {
        $order = $this->order();
        $other = Tenant::query()->create(['name' => 'Other Tenant', 'slug' => 'visibility-other', 'is_active' => true]);
        $actor = User::factory()->create(['tenant_id' => $other->id, 'can_login' => true]);
        Sanctum::actingAs($actor, ['customer'], 'sanctum');
        $this->assertFalse(OrderDelivery::query()->where('order_id', $order->id)->exists());
    }

    public function test_refund_ledger_uses_paid_total_and_preserves_original_fee_snapshot(): void
    {
        $order = $this->order();
        $order->storePayment(['method' => 'upi', 'amount' => 120]);
        $order->refreshDueAmount();
        $order->refresh();
        $this->assertSame(120.0, (float) $order->getRefundedAmount()->amount());
        $order->storePayment(['method' => 'upi', 'amount' => 100, 'type' => 'refund']);
        $order->refreshDueAmount();
        $order->refresh();
        $this->assertSame(20.0, (float) $order->getRefundedAmount()->amount());
        $this->assertSame(20.0, (float) $order->delivery->customer_delivery_fee);
        $order->storePayment(['method' => 'upi', 'amount' => 20, 'type' => 'refund']);
        $order->refreshDueAmount();
        $order->refresh();
        $this->assertSame(0.0, (float) $order->getRefundedAmount()->amount());
        $this->assertSame(20.0, (float) $order->delivery->customer_delivery_fee);
    }

    public function test_booking_result_after_cancellation_is_not_lost_when_token_is_cleared(): void
    {
        $order = $this->order();
        $token = (string) Str::uuid();
        $order->delivery()->update(['assignment_token' => $token, 'status' => DeliveryStatus::Assigning]);
        $order->update(['status' => OrderStatus::Cancelled]);
        app(MarkDeliveryAfterOrderCancellation::class)->handle(new OrderUpdateStatus($order, OrderStatus::Cancelled));
        $job = new \Modules\Order\Jobs\AssignOrderDelivery($this->tenant->id, $order->id);
        $assigned = new \ReflectionMethod($job, 'assigned');
        $quote = new \Modules\Order\Delivery\DeliveryQuote('test', 'Test Partner', 60, 20, 'quote-test');
        $assigned->invoke($job, $token, 'test-provider', $quote, 'late-test-booking', [], app(DeliveryCostCalculator::class));
        $delivery = $order->delivery()->firstOrFail();
        $this->assertSame('late-test-booking', $delivery->external_delivery_id);
        $this->assertSame('external_cancellation_required', $delivery->assignment_status);
        $this->assertSame(DeliveryStatus::ManualReviewRequired, $delivery->status);
        $this->assertSame(120.0, (float) $order->refresh()->total->amount());
    }

    public function test_distance_boundary_and_fractional_currency_snapshots(): void
    {
        $calculator = app(CustomerDeliveryQuote::class);
        foreach (['INR' => [20.13, 120.13], 'JPY' => [20.0, 120.0], 'KWD' => [20.125, 120.125]] as $currency => [$fee, $total]) {
            $this->branch->update(['currency' => $currency]);
            $settings = ['delivery_pricing_method' => 'slabs', 'delivery_charge_slabs' => [
                ['min_km' => 0, 'max_km' => 2, 'charge' => '20.125'], ['min_km' => 2, 'max_km' => 8, 'charge' => 40]]];
            foreach ([1.9999, 2.0, 2.0001] as $distance) {
                $quote = $calculator->calculate($this->branch, [...$this->address, 'longitude' => rad2deg($distance / 6371)], 100, $settings);
                $this->assertSame($distance > 2 ? 40.0 : $fee, $quote['delivery_fee']);
            }
            $this->assertSame($total, \Modules\Order\Delivery\DeliveryMoney::add(100, $fee, $currency));
        }
    }

    public function test_group_host_prepared_cart_uses_same_persisted_delivery_fee(): void
    {
        $headers = fn () => $this->headers(['Idempotency-Key' => (string) Str::uuid()]);
        $group = $this->postJson('/api/v1/customer-app/group-orders', ['branch_id' => $this->branch->id, 'order_type' => 'delivery'], $headers())->assertCreated()->json('body');
        $id = $group['id'];
        $group = $this->postJson("/api/v1/customer-app/group-orders/{$id}/items", ['version' => $group['version'],
            'product_id' => $this->product->id, 'quantity' => 1, 'options' => []], $headers())->assertOk()->json('body');
        $group = $this->postJson("/api/v1/customer-app/group-orders/{$id}/lock", ['version' => $group['version']], $headers())->assertOk()->json('body');
        $prepared = $this->postJson("/api/v1/customer-app/group-orders/{$id}/checkout", ['version' => $group['version']], $headers())->assertOk()->json('body');
        $this->cart = $prepared['checkout_cart_id'];
        $this->quote()->assertOk()->assertJsonPath('body.payable_total', 120);
        $this->submit()->assertCreated();
        $this->assertDatabaseHas('order_deliveries', ['customer_delivery_fee' => 20]);
        $this->assertSame(120.0, (float) Order::query()->latest('id')->firstOrFail()->due_amount->amount());
    }

    public function test_admin_read_model_protects_all_internal_financials(): void
    {
        $order = $this->order();
        $order->delivery()->update(['quote_history' => [['cost' => 60]], 'provider_final_cost' => 60]);
        $delivery = $order->delivery()->firstOrFail();
        $restricted = \Modules\Order\Delivery\DeliveryReadModel::admin($delivery, false);
        foreach (['provider_final_cost', 'provider_quoted_cost', 'quote_history', 'restaurant_contribution', 'platform_contribution', 'delivery_margin'] as $field) {
            $this->assertArrayNotHasKey($field, $restricted);
        }
        $allowed = \Modules\Order\Delivery\DeliveryReadModel::admin($delivery, true);
        $this->assertSame('60.0000', $allowed['provider_final_cost']);
    }

    public function test_whatsapp_delivery_materialization_persists_fee_and_rejects_unresolved_address(): void
    {
        $waAddress = [...$this->address, 'recipient' => 'Delivery Diner'];
        $profile = \Modules\WhatsAppCenter\Models\WhatsAppProviderProfile::query()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Test WhatsApp', 'ownership_mode' => 'restaurant_owned',
            'provider' => 'msg91', 'credentials' => ['auth_key' => 'test-only'], 'status' => 'connected', 'is_active' => true]);
        $number = $profile->phoneNumbers()->create(['provider_phone_id' => 'test-phone', 'display_number' => '+919999999999', 'status' => 'connected', 'is_active' => true]);
        $assignment = \Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment::query()->create([
            'tenant_id' => $this->tenant->id, 'provider_profile_id' => $profile->id, 'phone_number_id' => $number->id,
            'ownership_mode' => 'restaurant_owned', 'allowed_branch_ids' => [$this->branch->id], 'capabilities' => ['ordering' => true], 'is_active' => true]);
        $this->branch->forceFill(['is_accepting_orders' => true])->save();
        $this->customer->forceFill(['phone' => '919876543210', 'phone_verified_at' => now()])->save();
        $conversation = \Modules\WhatsAppCenter\Models\WhatsAppConversation::query()->create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'assignment_id' => $assignment->id, 'customer_phone' => '919876543210', 'customer_name' => 'Delivery Diner', 'state' => 'bot']);
        $session = \Modules\WhatsAppCenter\Models\WhatsAppOrderSession::query()->create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'conversation_id' => $conversation->id, 'cart_uuid' => (string) Str::uuid(), 'order_type' => 'delivery',
            'state' => 'waiting_approval', 'delivery_address' => $waAddress, 'quoted_total' => 100]);
        $cart = new \Modules\Cart\Cart(new \Modules\Cart\Storages\CartDBStorage, app('events'), 'cart', "cart_{$session->cart_uuid}", config('cart.cart'));
        $cart->addBranch($this->branch);
        $cart->addOrderType(OrderType::Delivery);
        $cart->store($this->product->id, 1);
        $engine = app(\Modules\WhatsAppCenter\Services\WhatsAppOrderingEngine::class);
        $materialize = new \ReflectionMethod($engine, 'materializeOrder');
        $missing = $waAddress;
        unset($missing['latitude'], $missing['longitude']);
        $session->update(['delivery_address' => $missing]);
        try {
            $materialize->invoke($engine, $session->refresh(), false);
            $this->fail('WhatsApp unresolved delivery address must be reviewed.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $session->update(['delivery_address' => [...$waAddress, 'longitude' => .2]]);
        try {
            $materialize->invoke($engine, $session->refresh(), false);
            $this->fail('WhatsApp outside-radius address must be reviewed.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $session->update(['delivery_address' => $waAddress]);
        $order = $materialize->invoke($engine, $session->refresh(), false);
        $this->assertSame(120.0, (float) $order->total->amount());
        $this->assertDatabaseHas('order_deliveries', ['order_id' => $order->id, 'customer_delivery_fee' => 20]);
        $this->assertSame(20.0, (float) data_get($order->fulfilmentDetails(), 'customer_delivery_fee'));
    }

    public function test_payment_failure_does_not_start_assignment_and_retry_keeps_same_fee(): void
    {
        $order = $this->order();
        setting(['customer_payment_razorpay_enabled' => true]);
        config(['payment.gateways.razorpay' => [
            'base_url' => 'https://api.razorpay.com/v1',
            'key_id' => 'rzp_test_platform',
            'key_secret' => 'platform-secret',
            'webhook_secret' => 'platform-webhook-secret',
            'partner_auth_enabled' => true,
        ]]);
        TenantPaymentGatewayConfig::query()->create(['tenant_id' => $this->tenant->id, 'provider' => 'razorpay',
            'enabled' => true, 'test_mode' => true, 'credentials' => ['linked_account_id' => 'acc_TestFailure1234']]);
        Http::fake(['api.razorpay.com/v1/orders' => Http::sequence()->push(['error' => 'test rejection'], 400)->push(['id' => 'retry_test'], 200)]);
        $service = app(RazorpayCheckoutService::class);
        try {
            $service->create($this->tenant->id, $this->customer->id, $order->id, (string) Str::uuid());
            $this->fail('Rejected payment initialization must fail.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('gateway', $exception->errors());
        }
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->payment_status);
        $this->assertNull($order->delivery->provider_final_cost);
        Queue::assertNothingPushed();
        $session = $service->create($this->tenant->id, $this->customer->id, $order->id, (string) Str::uuid());
        $this->assertSame(120.0, (float) $session->amount);
        $this->assertDatabaseCount('order_deliveries', 1);
    }

    public function test_wallet_above_total_and_free_delivery_use_final_server_amount(): void
    {
        setting(['free_delivery_above_order_amount' => 100]);
        DB::table('customer_wallet_accounts')->insert(['tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'currency' => 'INR', 'balance' => 150, 'created_at' => now(), 'updated_at' => now()]);
        $this->cart();
        $this->submit(['payment_method' => 'wallet', 'expected_payable_total' => 100])->assertCreated();
        $this->assertDatabaseHas('customer_wallet_accounts', ['customer_id' => $this->customer->id, 'balance' => 50]);
        $this->assertDatabaseHas('customer_wallet_transactions', ['customer_id' => $this->customer->id, 'amount' => 100]);
        $this->assertDatabaseHas('order_deliveries', ['customer_delivery_fee' => 0]);
    }

    public function test_schema_decimal_precision_foreign_key_and_unique_order_constraint(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL schema metadata check requires the isolated MySQL test engine.');
        }
        $columns = collect(DB::select('SELECT COLUMN_NAME, NUMERIC_PRECISION, NUMERIC_SCALE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['order_deliveries']))->keyBy('COLUMN_NAME');
        $this->assertSame(18, (int) $columns['customer_delivery_fee']->NUMERIC_PRECISION);
        $this->assertSame(4, (int) $columns['customer_delivery_fee']->NUMERIC_SCALE);
        $this->assertSame('YES', $columns['provider_final_cost']->IS_NULLABLE);
        $this->assertSame('YES', $columns['provider_correlation_id']->IS_NULLABLE);
        $this->assertSame('YES', $columns['booking_phase']->IS_NULLABLE);
        $this->assertTrue(DB::table('information_schema.KEY_COLUMN_USAGE')->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', 'order_deliveries')->where('COLUMN_NAME', 'order_id')->where('REFERENCED_TABLE_NAME', 'orders')->exists());
        $this->assertTrue(DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', 'order_deliveries')->where('COLUMN_NAME', 'provider_correlation_id')->where('NON_UNIQUE', 0)->exists());
    }

    public function test_invoice_escpos_does_not_repeat_delivery_fee_in_summary(): void
    {
        $order = $this->order();
        $invoice = app(CreateInvoiceServiceInterface::class)->create($order);
        $payload = ['order' => OrderResourceFactory::order($order), 'total' => $invoice->total->amount(),
            'subtotal' => $invoice->subtotal->amount(), 'currency_subunit' => 2,
            'branch' => ['name' => 'Test Outlet'], 'lines' => $invoice->lines->map(fn ($line) => OrderResourceFactory::invoiceLine($line))->all()];
        $receipt = json_encode(app(ExperimentalEscPosInvoiceTemplate::class)->render($payload, 48));
        $this->assertSame(1, substr_count($receipt, 'Customer Delivery'));
        $this->assertSame(0, substr_count($receipt, 'Delivery Fee '));
        $this->assertStringContainsString('120.00', $receipt);
    }

    public function test_delivery_address_and_order_type_edits_are_rejected_after_checkout(): void
    {
        $order = $this->order();
        try {
            $order->update(['type' => OrderType::Takeaway]);
            $this->fail('Checkout delivery order type cannot silently change.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('delivery', $exception->errors());
        }
        $order->refresh();
        $fulfilment = $order->fulfilmentDetails();
        $fulfilment['delivery_address']['longitude'] = .2;
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $order->update(['fulfilment' => $fulfilment]);
    }

    public function test_paid_order_edit_does_not_refund_fixed_delivery_charge_as_overpayment(): void
    {
        $order = $this->order();
        $order->storePayment(['method' => 'upi', 'amount' => 120]);
        $order->refreshDueAmount();
        $order->refresh();
        $cart = \Modules\Cart\Facades\Cart::getFacadeRoot();
        $cart->clear();
        $cart->addBranch($this->branch);
        $cart->addCustomer($this->customer->fresh());
        $cart->addOrderType(OrderType::Delivery);
        $cart->store($this->product->id, 1, [], $order->products()->firstOrFail());
        $updated = app(\Modules\Order\Services\SaveOrder\SaveOrderServiceInterface::class)->update($order->id, [
            'type' => 'delivery', 'register_id' => null, 'session_id' => null, 'submit_action' => 'hold', 'auto_print_kot' => false]);
        $this->assertSame(120.0, (float) $updated->refresh()->total->amount());
        $this->assertSame(0.0, (float) $updated->due_amount->amount());
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(20.0, (float) $updated->delivery->customer_delivery_fee);
    }

    public function test_zero_and_three_decimal_delivery_fees_persist_and_invoice_correctly(): void
    {
        setting(['delivery_charge_slabs' => [['min_km' => 0, 'max_km' => 8, 'charge' => '20.125']]]);
        foreach (['JPY' => [20.0, 120.0], 'KWD' => [20.125, 120.125]] as $currency => [$fee, $total]) {
            $this->branch->update(['currency' => $currency]);
            CurrencyRate::query()->firstOrCreate(['currency' => $currency], ['rate' => 1]);
            $this->cart = (string) Str::uuid();
            $this->cart();
            $preview = $this->quote()->assertOk();
            $this->assertSame($fee, (float) $preview->json('body.delivery_fee'));
            $this->submit(['expected_payable_total' => $total])->assertCreated();
            $order = Order::query()->latest('id')->firstOrFail();
            $this->assertSame($total, (float) $order->total->amount());
            $this->assertSame($fee, (float) $order->delivery->customer_delivery_fee);
            $invoice = app(CreateInvoiceServiceInterface::class)->create($order);
            $this->assertSame($total, (float) $invoice->refresh()->total->amount());
        }
    }

    public function test_full_void_refund_listener_returns_items_and_delivery_fee_before_and_after_preparation(): void
    {
        $register = \Modules\Pos\Models\PosRegister::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $session = \Modules\Pos\Models\PosSession::factory()->create(['branch_id' => $this->branch->id,
            'pos_register_id' => $register->id, 'opened_by' => $this->customer->id, 'opened_at' => now(), 'status' => 'open']);
        foreach ([OrderStatus::Pending, OrderStatus::Preparing] as $status) {
            $this->cart = (string) Str::uuid();
            $order = $this->order();
            $order->update(['status' => $status]);
            $order->storePayment(['method' => 'upi', 'amount' => 120]);
            $order->refreshDueAmount();
            $order->refresh();
            app(\Modules\Order\Listeners\OrderRefundAmount::class)->handle(new \Modules\Order\Events\OrderVoided(
                $order, OrderStatus::Refunded, \Modules\Payment\Enums\RefundPaymentMethod::BankTransfer, $session, 'Test full refund'));
            $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'type' => 'refund', 'amount' => 120]);
            $this->assertSame(20.0, (float) $order->delivery->customer_delivery_fee);
        }
    }

    public function test_booking_claim_is_database_backed_and_only_one_correlation_can_win(): void
    {
        $order = $this->order();
        $delivery = $order->delivery()->firstOrFail();
        $token = (string) Str::uuid();
        $delivery->update(['status' => DeliveryStatus::Assigning, 'assignment_token' => $token]);
        $guard = app(\Modules\Order\Delivery\DeliveryBookingGuard::class);

        // Model the stale pre-claim reads held by two workers. The guard must
        // ignore both stale instances and re-read the row under FOR UPDATE.
        $workerAView = OrderDelivery::query()->findOrFail($delivery->id);
        $workerBView = OrderDelivery::query()->findOrFail($delivery->id);
        $this->assertNull($workerAView->provider_correlation_id);
        $this->assertNull($workerBView->provider_correlation_id);

        $this->assertTrue($guard->claim($this->tenant->id, $order->id, $token, (string) Str::uuid()));
        $this->assertFalse($guard->claim($this->tenant->id, $order->id, $token, (string) Str::uuid()));
        $delivery->refresh();
        $this->assertSame(DeliveryStatus::BookingPending, $delivery->status);
        $this->assertSame('booking_pending', $delivery->assignment_status);
        $this->assertNotNull($delivery->provider_correlation_id);
        $this->assertSame('claimed', $delivery->booking_phase);
        $this->assertNotNull($delivery->booking_claimed_at);
        $this->assertNull($delivery->booking_requested_at);

        $correlation = $delivery->provider_correlation_id;
        $this->assertTrue($guard->markRequestStarting($this->tenant->id, $order->id, $token, $correlation));
        $this->assertFalse($guard->markRequestStarting($this->tenant->id, $order->id, $token, $correlation));
        $delivery->refresh();
        $this->assertSame('request_starting', $delivery->booking_phase);
        $this->assertNotNull($delivery->booking_requested_at);
    }

    public function test_order_cancellation_during_possible_booking_requires_investigation(): void
    {
        $order = $this->order();
        $token = (string) Str::uuid();
        $correlation = (string) Str::uuid();
        $order->delivery()->update([
            'status' => DeliveryStatus::BookingPending,
            'assignment_status' => 'booking_pending',
            'assignment_token' => $token,
            'provider_correlation_id' => $correlation,
            'booking_phase' => 'request_starting',
            'booking_requested_at' => now(),
        ]);
        $order->update(['status' => OrderStatus::Cancelled]);

        app(MarkDeliveryAfterOrderCancellation::class)->handle(new OrderUpdateStatus($order, OrderStatus::Cancelled));

        $delivery = $order->delivery()->firstOrFail();
        $this->assertSame(DeliveryStatus::Investigation, $delivery->status);
        $this->assertSame('unknown_provider_result', $delivery->assignment_status);
        $this->assertSame('ORDER_CANCELLED_DURING_BOOKING', $delivery->failure_code);
        $this->assertSame($correlation, $delivery->provider_correlation_id);
        $this->assertNull($delivery->cancelled_at);
    }

    public function test_order_cancellation_before_provider_transmission_releases_wallet_reservation(): void
    {
        $order = $this->order();
        $delivery = $order->delivery()->firstOrFail();
        $delivery->update([
            'status' => DeliveryStatus::BookingPending,
            'assignment_status' => 'booking_pending',
            'assignment_token' => (string) Str::uuid(),
            'provider_correlation_id' => (string) Str::uuid(),
            'booking_phase' => 'claimed',
            'booking_claimed_at' => now(),
            'booking_requested_at' => null,
        ]);
        app(DeliveryWallet::class)->reserve(
            $this->tenant->id, $delivery->id, $order->id, 40, 'delivery:'.$delivery->id.':reserve',
        );
        $order->update(['status' => OrderStatus::Cancelled]);

        app(MarkDeliveryAfterOrderCancellation::class)->handle(new OrderUpdateStatus($order, OrderStatus::Cancelled));

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Cancelled, $delivery->status);
        $this->assertSame('cancelled', $delivery->assignment_status);
        $this->assertNotNull($delivery->cancelled_at);
        $this->assertDatabaseHas('delivery_wallet_accounts', [
            'tenant_id' => $this->tenant->id,
            'available_balance' => 1000,
            'reserved_balance' => 0,
        ]);
        $this->assertDatabaseHas('delivery_wallet_transactions', [
            'tenant_id' => $this->tenant->id,
            'order_delivery_id' => $delivery->id,
            'type' => 'release',
        ]);
    }

    public function test_confirmed_delivery_cancellation_reverses_captured_wallet_charge_once(): void
    {
        $wallet = app(DeliveryWallet::class);
        $order = $this->order();
        $delivery = $order->delivery()->firstOrFail();
        $delivery->update([
            'provider' => 'uengage',
            'external_delivery_id' => 'TASK-CANCELLED-1',
            'status' => DeliveryStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
        $wallet->reserve($this->tenant->id, $delivery->id, $order->id, 75, 'cancel:test:reserve');
        $wallet->capture($this->tenant->id, $delivery->id, $order->id, 75, 'cancel:test:capture');
        $event = new DeliveryStatusChanged($this->tenant->id, $delivery->id, $order->id,
            DeliveryStatus::RiderSearching, DeliveryStatus::Cancelled);

        $listener = app(SettleDeliveryWalletAfterCancellation::class);
        $listener->handle($event);
        $listener->handle($event);

        $this->assertDatabaseHas('delivery_wallet_accounts', [
            'tenant_id' => $this->tenant->id, 'available_balance' => 1000, 'reserved_balance' => 0,
        ]);
        $this->assertSame(1, DB::table('delivery_wallet_transactions')->where('tenant_id', $this->tenant->id)
            ->where('order_delivery_id', $delivery->id)->where('type', 'refund')->count());
        $this->assertDatabaseHas('delivery_wallet_transactions', [
            'tenant_id' => $this->tenant->id,
            'order_delivery_id' => $delivery->id,
            'type' => 'refund',
            'amount' => 75,
            'idempotency_key' => 'delivery:'.$delivery->id.':refund-confirmed-cancellation',
        ]);
    }

    public function test_customer_delivery_read_model_hides_internal_incident_and_unsafe_tracking_url(): void
    {
        $delivery = $this->order()->delivery()->firstOrFail();
        $delivery->update([
            'status' => DeliveryStatus::Investigation,
            'rider_name' => 'Test Rider (OTP:5861)',
            'rider_phone' => '+919123456789',
            'tracking_url' => 'javascript:alert(1)',
            'provider_final_cost' => 55,
            'failure_reason' => 'Sensitive provider diagnostic',
        ]);

        $customer = \Modules\Order\Delivery\DeliveryReadModel::customer($delivery->fresh());
        $this->assertSame('arranging_delivery', $customer['status']);
        $this->assertSame('Delivery is being arranged', $customer['status_label']);
        $this->assertNull($customer['tracking_url']);
        $this->assertSame('Test Rider', $customer['rider_name']);
        $this->assertSame('+919123456789', $customer['rider_phone']);
        $this->assertSame('5861', $customer['delivery_otp']);
        $this->assertArrayNotHasKey('provider_final_cost', $customer);
        $this->assertArrayNotHasKey('failure_reason', $customer);

        foreach (['http://tracking.example.test/task/123', 'data:text/plain,test', 'file:///tmp/test',
            'https://user:password@tracking.example.test/task/123', 'https://'.str_repeat('a', 2048)] as $unsafe) {
            $delivery->update(['tracking_url' => $unsafe]);
            $this->assertNull(\Modules\Order\Delivery\DeliveryReadModel::customer($delivery->fresh())['tracking_url']);
        }

        $delivery->update(['tracking_url' => 'https://tracking.example.test/task/123']);
        $this->assertSame('https://tracking.example.test/task/123',
            \Modules\Order\Delivery\DeliveryReadModel::customer($delivery->fresh())['tracking_url']);
    }

    public function test_ambiguous_booking_result_stops_fallback_after_one_provider_call(): void
    {
        config(['delivery.integration_enabled' => true]);
        setting([
            'third_party_delivery_enabled' => true,
            'automatic_partner_assignment_enabled' => true,
            'delivery_quotes_enabled' => true,
            'delivery_cod_enabled' => true,
            'delivery_prepaid_enabled' => true,
            'auto_fallback_partner_enabled' => true,
            'delivery_selection_strategy' => 'cheapest',
            'maximum_delivery_eta_minutes' => null,
            'maximum_provider_delivery_cost' => null,
        ]);
        $order = $this->order();
        $order->update(['status' => OrderStatus::Preparing, 'payment_status' => OrderPaymentStatus::Paid]);
        $provider = new class implements \Modules\Order\Delivery\DeliveryProvider
        {
            public int $bookCalls = 0;

            public function code(): string
            {
                return 'test';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function quotes($pickup, $dropoff, string $orderReference, bool $cod): array
            {
                return [
                    new \Modules\Order\Delivery\DeliveryQuote('first', 'First', 40, 10, 'q1'),
                    new \Modules\Order\Delivery\DeliveryQuote('second', 'Second', 50, 12, 'q2'),
                ];
            }

            public function book($quote, string $orderReference, string $idempotencyKey): \Modules\Order\Delivery\DeliveryBookingResult
            {
                $this->bookCalls++;

                return new \Modules\Order\Delivery\DeliveryBookingResult(false, failureCode: 'PROVIDER_5XX');
            }
        };

        (new \Modules\Order\Jobs\AssignOrderDelivery($this->tenant->id, $order->id))->handle(
            app(\Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService::class),
            $provider,
            app(\Modules\Order\Delivery\DeliveryQuoteSelector::class),
            app(DeliveryCostCalculator::class),
            app(\Modules\Order\Delivery\DeliveryBookingGuard::class),
            app(DeliveryWallet::class),
        );

        $delivery = $order->delivery()->firstOrFail();
        $this->assertSame(1, $provider->bookCalls);
        $this->assertSame(1, $delivery->assignment_attempts);
        $this->assertSame(DeliveryStatus::Investigation, $delivery->status);
        $this->assertSame('ambiguous', $delivery->booking_phase);
        $this->assertSame('PROVIDER_5XX', $delivery->failure_code);
        $this->assertNotNull($delivery->provider_correlation_id);
        $this->assertNull($delivery->external_delivery_id);
        $this->assertDatabaseHas('delivery_wallet_accounts', [
            'tenant_id' => $this->tenant->id, 'available_balance' => 960, 'reserved_balance' => 40,
        ]);
    }

    public function test_paid_cash_preference_order_is_dispatched_as_prepaid(): void
    {
        config(['delivery.integration_enabled' => true]);
        setting([
            'third_party_delivery_enabled' => true,
            'automatic_partner_assignment_enabled' => true,
            'delivery_quotes_enabled' => true,
            'delivery_cod_enabled' => false,
            'delivery_prepaid_enabled' => true,
            'delivery_selection_strategy' => 'cheapest',
        ]);
        $order = $this->order();
        $order->update(['status' => OrderStatus::Preparing, 'payment_status' => OrderPaymentStatus::Paid]);
        $provider = new class implements \Modules\Order\Delivery\DeliveryProvider
        {
            public ?bool $quotedAsCod = null;

            public function code(): string { return 'test'; }
            public function isConfigured(): bool { return true; }

            public function quotes($pickup, $dropoff, string $orderReference, bool $cod): array
            {
                $this->quotedAsCod = $cod;

                return [];
            }

            public function book($quote, string $orderReference, string $idempotencyKey): \Modules\Order\Delivery\DeliveryBookingResult
            {
                throw new \RuntimeException('No quote should be booked.');
            }
        };

        (new \Modules\Order\Jobs\AssignOrderDelivery($this->tenant->id, $order->id))->handle(
            app(\Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService::class),
            $provider,
            app(\Modules\Order\Delivery\DeliveryQuoteSelector::class),
            app(DeliveryCostCalculator::class),
            app(\Modules\Order\Delivery\DeliveryBookingGuard::class),
            app(DeliveryWallet::class),
        );

        $this->assertFalse($provider->quotedAsCod);
        $this->assertNotSame('PAYMENT_METHOD_DISABLED', $order->delivery()->firstOrFail()->failure_code);
    }

    public function test_unpaid_preparing_order_never_requests_a_delivery_partner(): void
    {
        $order = $this->order();
        config(['delivery.integration_enabled' => true]);
        setting([
            'third_party_delivery_enabled' => true,
            'automatic_partner_assignment_enabled' => true,
            'delivery_quotes_enabled' => true,
            'delivery_cod_enabled' => true,
            'delivery_prepaid_enabled' => true,
            'delivery_selection_strategy' => 'cheapest',
        ]);
        $order->update(['status' => OrderStatus::Preparing, 'payment_status' => OrderPaymentStatus::Unpaid]);
        $provider = new class implements \Modules\Order\Delivery\DeliveryProvider
        {
            public int $quoteCalls = 0;

            public function code(): string
            {
                return 'test';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function quotes($pickup, $dropoff, string $orderReference, bool $cod): array
            {
                $this->quoteCalls++;

                return [];
            }

            public function book($quote, string $orderReference, string $idempotencyKey): \Modules\Order\Delivery\DeliveryBookingResult
            {
                throw new \LogicException('An unpaid order must never reach booking.');
            }
        };

        (new \Modules\Order\Jobs\AssignOrderDelivery($this->tenant->id, $order->id))->handle(
            app(\Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService::class),
            $provider,
            app(\Modules\Order\Delivery\DeliveryQuoteSelector::class),
            app(DeliveryCostCalculator::class),
            app(\Modules\Order\Delivery\DeliveryBookingGuard::class),
            app(DeliveryWallet::class),
        );

        $this->assertSame(0, $provider->quoteCalls);
        $this->assertNull($order->delivery()->first()->assignment_token);
    }

    public function test_authenticated_uengage_callbacks_advance_a_paid_ready_order_without_optional_order_id(): void
    {
        $token = str_repeat('w', 48);
        config([
            'delivery.webhook_enabled' => true,
            'delivery.webhook_token_hash' => hash('sha256', $token),
        ]);
        $order = $this->order();
        $order->update(['status' => OrderStatus::Ready, 'payment_status' => OrderPaymentStatus::Paid]);
        $delivery = $order->delivery()->firstOrFail();
        $delivery->update([
            'provider' => 'uengage',
            'mode' => 'third_party',
            'external_delivery_id' => 'UEN-TEST-CALLBACK',
            'status' => DeliveryStatus::RiderAssigned,
        ]);

        $this->postJson('/api/v1/delivery/uengage/webhook', [
            'status' => true,
            'data' => ['taskId' => 'UEN-TEST-CALLBACK', 'rider_name' => 'Test Rider'],
            'status_code' => 'DISPATCHED',
        ], ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->assertSame(DeliveryStatus::PickedUp, $delivery->fresh()->status);
        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $order->id,
            'status' => OrderStatus::OutForDelivery->value,
            'note' => 'UENGAGE_DELIVERY_STATUS',
        ]);

        $this->postJson('/api/v1/delivery/uengage/webhook', [
            'status' => true,
            'data' => ['taskId' => 'UEN-TEST-CALLBACK'],
            'status_code' => 'DELIVERED',
        ], ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->assertSame(DeliveryStatus::Delivered, $delivery->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->closed_at);

        // A replay is idempotent and cannot rewrite the rider identity.
        $this->postJson('/api/v1/delivery/uengage/webhook', [
            'status' => true,
            'data' => ['taskId' => 'UEN-TEST-CALLBACK', 'rider_name' => 'Unexpected Rewrite'],
            'status_code' => 'DELIVERED',
        ], ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->assertSame('Test Rider', $delivery->fresh()->rider_name);
    }

    public function test_uengage_task_payload_uses_the_paid_tenant_order_and_exact_flash_contract(): void
    {
        $this->branch->update([
            'name' => 'Test Outlet', 'phone' => '919876543210',
            'address_line1' => '1 Test Road', 'city' => 'Test City',
        ]);
        $order = $this->order();
        $order->update(['payment_status' => OrderPaymentStatus::Paid]);
        app(TenantContext::class)->set($this->tenant);

        $payload = app(\Modules\Order\Delivery\UengageTaskPayload::class)
            ->forOrder($order->reference_no, '69840');

        $this->assertSame('69840', $payload['storeId']);
        $this->assertSame($order->reference_no, $payload['order_details']['vendor_order_id']);
        $this->assertSame('true', $payload['order_details']['paid']);
        $this->assertSame(120.0, $payload['order_details']['order_total']);
        $this->assertSame('Test Outlet', $payload['pickup_details']['name']);
        $this->assertSame('Delivery Diner', $payload['drop_details']['name']);
        $this->assertSame(0.009, $payload['drop_details']['longitude']);
        $this->assertSame(1, $payload['order_items'][0]['quantity']);
        $this->assertArrayNotHasKey('authentication', $payload);

        config(['delivery.integration_enabled' => true, 'delivery.sandbox_enabled' => true,
            'delivery.booking_enabled' => true, 'delivery.uengage.environment' => 'sandbox']);
        $credentials = \Mockery::mock(\Modules\Order\Delivery\PlatformDeliveryCredentials::class);
        $credentials->shouldReceive('apiKey')->andReturn('test-token');
        $credentials->shouldReceive('storeId')->andReturn('69840');
        $this->app->instance(\Modules\Order\Delivery\PlatformDeliveryCredentials::class, $credentials);
        Http::fake(['https://riderapi-staging.uengage.in/createTask' => Http::response([
            'status' => true, 'taskId' => 'UEN-CREATED-ONCE', 'Status_code' => 'ACCEPTED',
        ])]);

        $result = app(\Modules\Order\Delivery\UengageDeliveryProvider::class)->book(
            new \Modules\Order\Delivery\DeliveryQuote('uengage_auto', 'uEngage auto allocation', 40, null, null),
            $order->reference_no,
            'local-guard-owned-key',
        );
        $this->assertTrue($result->successful);
        $this->assertSame('UEN-CREATED-ONCE', $result->externalDeliveryId);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://riderapi-staging.uengage.in/createTask'
            && $request->hasHeader('access-token', 'test-token')
            && $request['storeId'] === '69840'
            && $request['order_details']['vendor_order_id'] === $order->reference_no);
    }

    public function test_insufficient_delivery_wallet_blocks_provider_booking_before_transmission(): void
    {
        config(['delivery.integration_enabled' => true]);
        setting([
            'third_party_delivery_enabled' => true,
            'automatic_partner_assignment_enabled' => true,
            'delivery_quotes_enabled' => true,
            'delivery_cod_enabled' => true,
            'delivery_prepaid_enabled' => true,
            'delivery_selection_strategy' => 'cheapest',
            'maximum_delivery_eta_minutes' => null,
            'maximum_provider_delivery_cost' => null,
        ]);
        app(DeliveryWallet::class)->adjust($this->tenant->id, 'debit', 1000, (string) Str::uuid(), 'Drain test delivery wallet before assignment.', null);
        $order = $this->order();
        $order->update(['status' => OrderStatus::Preparing, 'payment_status' => OrderPaymentStatus::Paid]);
        $provider = new class implements \Modules\Order\Delivery\DeliveryProvider
        {
            public int $bookCalls = 0;

            public function code(): string
            {
                return 'test';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function quotes($pickup, $dropoff, string $orderReference, bool $cod): array
            {
                return [new \Modules\Order\Delivery\DeliveryQuote('first', 'First', 40, 10, 'q1')];
            }

            public function book($quote, string $orderReference, string $idempotencyKey): \Modules\Order\Delivery\DeliveryBookingResult
            {
                $this->bookCalls++;

                return new \Modules\Order\Delivery\DeliveryBookingResult(true, 'MUST-NOT-BE-CREATED');
            }
        };

        (new \Modules\Order\Jobs\AssignOrderDelivery($this->tenant->id, $order->id))->handle(
            app(\Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService::class),
            $provider,
            app(\Modules\Order\Delivery\DeliveryQuoteSelector::class),
            app(DeliveryCostCalculator::class),
            app(\Modules\Order\Delivery\DeliveryBookingGuard::class),
            app(DeliveryWallet::class),
        );

        $delivery = $order->delivery()->firstOrFail();
        $this->assertSame(0, $provider->bookCalls);
        $this->assertSame(DeliveryStatus::ManualReviewRequired, $delivery->status);
        $this->assertSame('DELIVERY_WALLET_INSUFFICIENT', $delivery->failure_code);
        $this->assertNull($delivery->booking_requested_at);
        $this->assertNull($delivery->provider_correlation_id);
        $this->assertNull($delivery->booking_phase);
        $this->assertNull($delivery->booking_claimed_at);
        $this->assertDatabaseHas('delivery_wallet_accounts', [
            'tenant_id' => $this->tenant->id, 'available_balance' => 0, 'reserved_balance' => 0,
        ]);
    }

    public function test_provider_task_id_survives_full_assignment_persistence_failure(): void
    {
        config(['delivery.integration_enabled' => true]);
        setting([
            'third_party_delivery_enabled' => true,
            'automatic_partner_assignment_enabled' => true,
            'delivery_quotes_enabled' => true,
            'delivery_cod_enabled' => true,
            'delivery_prepaid_enabled' => true,
            'auto_fallback_partner_enabled' => false,
            'delivery_selection_strategy' => 'cheapest',
            'maximum_delivery_eta_minutes' => null,
            'maximum_provider_delivery_cost' => null,
        ]);
        $order = $this->order();
        $order->update(['status' => OrderStatus::Preparing, 'payment_status' => OrderPaymentStatus::Paid]);
        $provider = new class implements \Modules\Order\Delivery\DeliveryProvider
        {
            public int $bookCalls = 0;

            public function code(): string
            {
                return 'test';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function quotes($pickup, $dropoff, string $orderReference, bool $cod): array
            {
                // Exceeds the partner-name column only during the full
                // assignment snapshot, after wallet capture and provider success.
                return [new \Modules\Order\Delivery\DeliveryQuote('first', str_repeat('X', 500), 40, 10, 'q1')];
            }

            public function book($quote, string $orderReference, string $idempotencyKey): \Modules\Order\Delivery\DeliveryBookingResult
            {
                $this->bookCalls++;

                return new \Modules\Order\Delivery\DeliveryBookingResult(true, 'TASK-KNOWN-AFTER-DB-FAILURE');
            }
        };

        (new \Modules\Order\Jobs\AssignOrderDelivery($this->tenant->id, $order->id))->handle(
            app(\Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService::class),
            $provider,
            app(\Modules\Order\Delivery\DeliveryQuoteSelector::class),
            app(DeliveryCostCalculator::class),
            app(\Modules\Order\Delivery\DeliveryBookingGuard::class),
            app(DeliveryWallet::class),
        );

        $delivery = $order->delivery()->firstOrFail();
        $this->assertSame(1, $provider->bookCalls);
        $this->assertSame('TASK-KNOWN-AFTER-DB-FAILURE', $delivery->external_delivery_id);
        $this->assertSame(DeliveryStatus::Investigation, $delivery->status);
        $this->assertSame('confirmed', $delivery->booking_phase);
        $this->assertSame('BOOKING_PERSISTENCE_FAILED', $delivery->failure_code);
        $this->assertSame('booking_confirmation_persistence_failed', $delivery->assignment_status);
        $this->assertNull($delivery->provider_final_cost);
        $this->assertSame(120.0, (float) $order->refresh()->total->amount());
        $this->assertDatabaseHas('delivery_wallet_accounts', [
            'tenant_id' => $this->tenant->id, 'available_balance' => 960, 'reserved_balance' => 0,
        ]);
    }

    public function test_delivery_wallet_ledger_is_idempotent_and_never_goes_negative(): void
    {
        $wallet = app(DeliveryWallet::class);
        $delivery = $this->order()->delivery()->firstOrFail();
        $wallet->reserve($this->tenant->id, $delivery->id, $delivery->order_id, 75, 'delivery:test:reserve');
        $wallet->reserve($this->tenant->id, $delivery->id, $delivery->order_id, 75, 'delivery:test:reserve');
        try {
            $wallet->reserve($this->tenant->id, $delivery->id, $delivery->order_id, 75, 'delivery:test:second-reserve');
            $this->fail('A second logical reservation was accepted for one delivery.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertDatabaseCount('delivery_wallet_transactions', 2);
        }
        $wallet->capture($this->tenant->id, $delivery->id, $delivery->order_id, 75, 'delivery:test:capture');

        try {
            $wallet->release($this->tenant->id, $delivery->id, $delivery->order_id, 1, 'delivery:test:invalid-release', 'Invalid release.');
            $this->fail('A settled delivery reservation was released twice.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertDatabaseCount('delivery_wallet_transactions', 3);
        }

        $this->assertDatabaseHas('delivery_wallet_accounts', [
            'tenant_id' => $this->tenant->id, 'available_balance' => 925, 'reserved_balance' => 0,
        ]);
        $this->assertSame(3, DB::table('delivery_wallet_transactions')->where('tenant_id', $this->tenant->id)->count());

        try {
            $wallet->adjust($this->tenant->id, 'debit', 926, (string) Str::uuid(), 'Attempt invalid overdraw adjustment.', null);
            $this->fail('Wallet overdraft was accepted.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertDatabaseHas('delivery_wallet_accounts', ['tenant_id' => $this->tenant->id, 'available_balance' => 925]);
        }
    }

    public function test_tenant_can_read_wallet_but_cannot_credit_itself(): void
    {
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_active' => true]);
        $deliveryPermission = Permission::findOrCreate('admin.delivery_settings.edit', 'api');
        $tenantPermission = Permission::findOrCreate('admin.tenants.edit', 'api');
        $admin->givePermissionTo([$deliveryPermission, $tenantPermission]);
        Sanctum::actingAs($admin, ['*'], 'api');

        $walletResponse = $this->getJson('/api/v1/settings/delivery/wallet');
        $this->assertSame(200, $walletResponse->status(), $walletResponse->getContent());
        $this->assertEquals(1000.0, $walletResponse->json('body.account.available_balance'));
        $walletResponse->assertJsonPath('body.transaction_pagination.per_page', 10)
            ->assertJsonPath('body.transaction_pagination.page', 1);
        $this->getJson('/api/v1/settings/delivery/wallet?page=999&limit=10')
            ->assertOk()
            ->assertJsonPath('body.transaction_pagination.page', 1)
            ->assertJsonPath('body.transaction_pagination.last_page', 1);
        $this->postJson('/api/v1/tenants/'.$this->tenant->id.'/delivery-wallet/adjust', [
            'type' => 'credit', 'amount' => 500, 'reason' => 'Tenant must not self fund wallet.', 'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->assertDatabaseHas('delivery_wallet_accounts', ['tenant_id' => $this->tenant->id, 'available_balance' => 1000]);
    }

    public function test_provider_refund_is_idempotent_and_cannot_exceed_captured_cost(): void
    {
        $wallet = app(DeliveryWallet::class);
        $delivery = $this->order()->delivery()->firstOrFail();
        $wallet->reserve($this->tenant->id, $delivery->id, $delivery->order_id, 75, 'refund:test:reserve');
        $wallet->capture($this->tenant->id, $delivery->id, $delivery->order_id, 75, 'refund:test:capture');

        $refund = $wallet->refundCapture($this->tenant->id, $delivery->id, $delivery->order_id, 25,
            'refund:test:confirmed', 'Confirmed provider partial refund.', 'PROVIDER-REFUND-1', null);
        $replay = $wallet->refundCapture($this->tenant->id, $delivery->id, $delivery->order_id, 25,
            'refund:test:confirmed', 'Confirmed provider partial refund.', 'PROVIDER-REFUND-1', null);

        $this->assertFalse($refund->_idempotent_replay);
        $this->assertTrue($replay->_idempotent_replay);
        $this->assertDatabaseHas('delivery_wallet_accounts', [
            'tenant_id' => $this->tenant->id, 'available_balance' => 950, 'reserved_balance' => 0,
        ]);
        $this->assertSame(1, DB::table('delivery_wallet_transactions')->where('tenant_id', $this->tenant->id)
            ->where('type', 'refund')->count());

        try {
            $wallet->refundCapture($this->tenant->id, $delivery->id, $delivery->order_id, 10,
                'refund:test:different-key', 'Duplicate provider refund reference.', 'PROVIDER-REFUND-1', null);
            $this->fail('A provider refund reference was posted twice.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertDatabaseHas('delivery_wallet_accounts', ['tenant_id' => $this->tenant->id, 'available_balance' => 950]);
        }

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $wallet->refundCapture($this->tenant->id, $delivery->id, $delivery->order_id, 51,
            'refund:test:over', 'Invalid provider over-refund.', 'PROVIDER-REFUND-2', null);
    }

    public function test_platform_operator_can_post_audited_delivery_wallet_adjustments(): void
    {
        $operator = User::factory()->create(['tenant_id' => null, 'branch_id' => null, 'is_active' => true]);
        $operator->forceFill(['tenant_id' => null, 'branch_id' => null])->save();
        $permission = Permission::findOrCreate('admin.tenants.edit', 'api');
        $operator->givePermissionTo($permission);
        Sanctum::actingAs($operator, ['*'], 'api');

        $this->getJson('/api/v1/tenants/'.$this->tenant->id.'/delivery-wallet')
            ->assertOk()->assertJsonPath('body.account.available_balance', '1000.0000');
        $key = (string) Str::uuid();
        $payload = ['type' => 'credit', 'amount' => 250, 'reason' => 'Platform settlement funding credit.', 'idempotency_key' => $key];
        $this->postJson('/api/v1/tenants/'.$this->tenant->id.'/delivery-wallet/adjust', $payload)
            ->assertOk()->assertJsonPath('body.idempotent_replay', false);
        $this->postJson('/api/v1/tenants/'.$this->tenant->id.'/delivery-wallet/adjust', $payload)
            ->assertOk()->assertJsonPath('body.idempotent_replay', true);

        $this->assertDatabaseHas('delivery_wallet_accounts', ['tenant_id' => $this->tenant->id, 'available_balance' => 1250]);
        $this->assertSame(1, DB::table('delivery_wallet_transactions')->where('tenant_id', $this->tenant->id)
            ->where('idempotency_key', $key)->count());
        $this->assertDatabaseHas('delivery_wallet_transactions', [
            'tenant_id' => $this->tenant->id, 'idempotency_key' => $key, 'actor_id' => $operator->id,
        ]);
    }

    public function test_platform_tenant_registry_includes_delivery_wallet_balances(): void
    {
        $operator = User::factory()->create(['tenant_id' => null, 'branch_id' => null, 'is_active' => true]);
        $operator->forceFill(['tenant_id' => null, 'branch_id' => null])->save();
        $operator->givePermissionTo(Permission::findOrCreate('admin.tenants.index', 'api'));
        Sanctum::actingAs($operator, ['*'], 'api');

        $response = $this->getJson('/api/v1/tenants?filters[search]=Delivery%20Tests')->assertOk();
        $tenant = collect($response->json('body.data'))->firstWhere('id', $this->tenant->id);

        $this->assertNotNull($tenant);
        $this->assertSame(1000.0, (float) data_get($tenant, 'delivery_wallet.available_balance'));
        $this->assertSame(0.0, (float) data_get($tenant, 'delivery_wallet.reserved_balance'));
        $this->assertTrue((bool) data_get($tenant, 'delivery_wallet.has_account'));
    }

    public function test_platform_delivery_wallet_is_hidden_without_delivery_entitlement(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'No Delivery Plan', 'slug' => 'no-delivery-plan', 'is_active' => true,
        ]);
        $operator = User::factory()->create(['tenant_id' => null, 'branch_id' => null, 'is_active' => true]);
        $operator->forceFill(['tenant_id' => null, 'branch_id' => null])->save();
        $operator->givePermissionTo(Permission::findOrCreate('admin.tenants.edit', 'api'));
        Sanctum::actingAs($operator, ['*'], 'api');

        $this->getJson('/api/v1/tenants/'.$tenant->id.'/delivery-wallet')->assertNotFound();
        $this->postJson('/api/v1/tenants/'.$tenant->id.'/delivery-wallet/adjust', [
            'type' => 'credit', 'amount' => 100, 'reason' => 'Must remain unavailable without entitlement.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->assertDatabaseMissing('delivery_wallet_accounts', ['tenant_id' => $tenant->id]);
    }
}
