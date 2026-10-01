<?php

namespace Tests\Feature\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Notification\Services\WhatsApp\WhatsAppProviderFactory;
use Modules\Notification\Services\WhatsApp\WhatsAppProviderInterface;
use Modules\Order\Jobs\SendStaffOrderPhoneAlerts;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

class StaffOrderPhoneAlertsTest extends TestCase
{
    use AggregatorTestSupport, RefreshDatabase;

    private $tenant;

    private $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
        Http::preventStrayRequests();
        Cache::flush();
        $this->tenant = Tenant::query()->withoutGlobalScopes()->create(['name' => 'Staff alerts', 'slug' => Str::random(12), 'is_active' => true]);
        app(TenantContext::class)->set($this->tenant);
        $branch = $this->makeBranch(['tenant_id' => $this->tenant->id]);
        $this->order = $this->makeOrder($branch, ['fulfilment' => ['source' => 'customer_app']]);
        setting(['notifications_enabled' => true, 'order_phone_alerts_enabled' => true, 'whatsapp_enabled' => true,
            'whatsapp_provider' => 'meta', 'whatsapp_meta_access_token' => 'mock-secret', 'whatsapp_meta_phone_number_id' => 'mock-id',
            'order_phone_alert_numbers' => ['+917389175732', '+919876543210'],
            'order_phone_alert_sources' => ['customer_app'], 'order_phone_alert_template_id' => 'staff_alert_approved',
            'whatsapp_templates' => [['id' => 'staff_alert_approved', 'template_id' => 'staff_alert_approved', 'event' => 'staff_order_received', 'is_active' => true, 'variables' => ['order_number', 'source', 'branch_name', 'order_total']]]]);
    }

    private function runJob($factory, ?int $tenantId = null): void
    {
        (new SendStaffOrderPhoneAlerts($this->order->id, $tenantId ?? $this->tenant->id))->handle(app(TenantContext::class), app(SettingServiceInterface::class), $factory);
    }

    public function test_multiple_recipients_receive_only_safe_order_fields_and_duplicates_are_suppressed(): void
    {
        $provider = \Mockery::mock(WhatsAppProviderInterface::class);
        $provider->shouldReceive('sendTemplate')->twice()->withArgs(function ($number, $template, $parameters) {
            return in_array($number, ['+917389175732', '+919876543210'], true) && $template === 'staff_alert_approved'
                && $parameters['order_number'] === $this->order->reference_no
                && str_contains($parameters['source'], 'Customer App')
                && str_contains($parameters['source'], 'Customer App · Payment: Payment pending • Items:')
                && ! str_contains($parameters['source'], 'Delivery slot:')
                && ! str_contains($parameters['source'], 'Instructions:')
                && ! str_contains($parameters['source'], "\n")
                && ! str_contains($parameters['source'], "\t")
                && array_keys($parameters) === ['order_number', 'source', 'branch_name', 'order_total'];
        })->andReturn(['success' => true]);
        $factory = \Mockery::mock(WhatsAppProviderFactory::class);
        $factory->shouldReceive('make')->twice()->andReturn($provider);
        $this->runJob($factory);
        $this->runJob($factory);
        $this->assertSame(2, \Modules\Notification\Models\WhatsAppLog::count());
    }

    public function test_dedicated_items_template_keeps_source_short_and_sends_item_names(): void
    {
        $product = \Modules\Product\Models\Product::factory()->create([
            'menu_id' => $this->makeMenu($this->order->branch)->id,
            'name' => 'Paneer Butter Masala',
        ]);
        $this->order->products()->create([
            'product_id' => $product->id, 'currency' => 'INR', 'currency_rate' => 1,
            'unit_price' => 150, 'quantity' => 2, 'subtotal' => 300, 'tax_total' => 0,
            'total' => 300, 'cost_price' => 0, 'revenue' => 300,
        ]);
        setting(['order_phone_alert_numbers' => ['+917389175732'],
            'order_phone_alert_template_id' => 'staff_alert_items_link',
            'whatsapp_templates' => [['id' => 'staff_alert_items_link', 'template_id' => 'staff_alert_items_link',
                'event' => 'staff_order_received', 'is_active' => true,
                'variables' => ['order_number', 'source', 'items', 'branch_name', 'order_total', 'order_link']]]]);
        $provider = \Mockery::mock(WhatsAppProviderInterface::class);
        $provider->shouldReceive('sendTemplate')->once()->withArgs(fn ($number, $template, $parameters) => $number === '+917389175732' && $template === 'staff_alert_items_link'
            && $parameters['source'] === 'Customer App · Payment: Payment pending'
            && $parameters['items'] === 'Paneer Butter Masala × 2'
            && str_starts_with($parameters['order_link'], 'https://')
            && array_keys($parameters) === ['order_number', 'source', 'items', 'branch_name', 'order_total', 'order_link']
        )->andReturn(['success' => true]);
        $factory = \Mockery::mock(WhatsAppProviderFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($provider);
        $this->runJob($factory);
        $this->assertSame(1, \Modules\Notification\Models\WhatsAppLog::count());
    }

    public function test_link_template_opens_the_exact_order_on_the_tenant_domain(): void
    {
        $this->tenant->forceFill(['domain' => 'snack-sprint.nexdine.myteknoland.in'])->save();
        setting(['order_phone_alert_numbers' => ['+917389175732'],
            'order_phone_alert_template_id' => 'staff_alert_with_link',
            'whatsapp_templates' => [['id' => 'staff_alert_with_link', 'template_id' => 'staff_alert_with_link',
                'event' => 'staff_order_received', 'is_active' => true,
                'variables' => ['order_number', 'source', 'branch_name', 'order_total', 'order_link']]]]);
        $provider = \Mockery::mock(WhatsAppProviderInterface::class);
        $expected = 'https://snack-sprint.nexdine.myteknoland.in/admin/orders/'.$this->order->id.'/show';
        $provider->shouldReceive('sendTemplate')->once()->withArgs(fn ($number, $template, $parameters) => $number === '+917389175732' && $template === 'staff_alert_with_link'
            && $parameters['order_link'] === $expected
            && array_keys($parameters) === ['order_number', 'source', 'branch_name', 'order_total', 'order_link']
        )->andReturn(['success' => true]);
        $factory = \Mockery::mock(WhatsAppProviderFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($provider);
        $this->runJob($factory);
        $this->assertSame(1, \Modules\Notification\Models\WhatsAppLog::count());
    }

    public function test_disabling_alerts_suppresses_queued_sends(): void
    {
        setting(['order_phone_alerts_enabled' => false]);
        $factory = \Mockery::mock(WhatsAppProviderFactory::class);
        $factory->shouldNotReceive('make');
        $this->runJob($factory);
        $this->assertSame(0, \Modules\Notification\Models\WhatsAppLog::count());
    }

    public function test_source_filter_blocks_unselected_orders(): void
    {
        setting(['order_phone_alert_sources' => ['whatsapp']]);
        $factory = \Mockery::mock(WhatsAppProviderFactory::class);
        $factory->shouldNotReceive('make');
        $this->runJob($factory);
        $this->assertSame(0, \Modules\Notification\Models\WhatsAppLog::count());
    }

    public function test_another_tenant_cannot_send_alert_for_this_order(): void
    {
        $other = Tenant::query()->withoutGlobalScopes()->create(['name' => 'Other', 'slug' => Str::random(12), 'is_active' => true]);
        $factory = \Mockery::mock(WhatsAppProviderFactory::class);
        $factory->shouldNotReceive('make');
        $this->runJob($factory, $other->id);
        $this->assertSame($this->tenant->id, app(TenantContext::class)->id());
    }

    public function test_customer_template_cannot_be_used_for_staff_alerts(): void
    {
        setting(['whatsapp_templates' => [['id' => 'staff_alert_approved', 'event' => 'order_submitted', 'variables' => ['customer_name']]]]);
        $factory = \Mockery::mock(WhatsAppProviderFactory::class);
        $factory->shouldNotReceive('make');
        $this->runJob($factory);
        $this->assertSame(0, \Modules\Notification\Models\WhatsAppLog::count());
    }
}
