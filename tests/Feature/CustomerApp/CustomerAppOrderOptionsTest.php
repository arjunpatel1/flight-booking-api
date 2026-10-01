<?php

namespace Tests\Feature\CustomerApp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Branch\Models\Branch;
use Modules\Category\Models\Category;
use Modules\Currency\Models\CurrencyRate;
use Modules\Menu\Models\Menu;
use Modules\Menu\Models\OnlineMenu;
use Modules\Option\Models\Option;
use Modules\Option\Models\OptionValue;
use Modules\Order\Models\Order;
use Modules\Product\Models\Product;
use Modules\Saas\Models\CustomerAppRegistration;
use Modules\Saas\Models\Tenant;
use Modules\Support\Enums\PriceType;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\Role;
use Modules\User\Models\User;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

/**
 * End-to-end proof that a customer-app order carries its chosen variants.
 *
 * The Flutter client used to send `options: {}` for every line, so an item
 * with a required option group reached the kitchen unpriced and unconfigured.
 * These tests drive the same cart + order endpoints the app now calls.
 */
class CustomerAppOrderOptionsTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private Menu $menu;
    private OnlineMenu $onlineMenu;
    private Product $product;
    private Option $size;
    private Option $addons;
    private string $appToken;
    private string $cartId;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broadcasting.default' => 'null']);
        Queue::fake();
        $this->setUpAggregatorTestSupport();
        setting(['default_currency' => 'INR']);
        CurrencyRate::query()->create(['currency' => 'INR', 'rate' => 1]);

        $this->tenant = $this->makeTenant('acme');
        $this->appToken = $this->issueAppSession($this->tenant);
        $this->customer = User::query()->create([
            'name' => 'Test Diner',
            'username' => 'customer_'.Str::lower(Str::random(12)),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => bcrypt('secret-Passw0rd'),
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->customer->forceFill(['tenant_id' => $this->tenant->id])->saveQuietly();
        Role::findOrCreate(DefaultRole::Customer->value, 'api');
        $this->customer->assignRole(DefaultRole::Customer->value);
        Sanctum::actingAs($this->customer, ['customer'], 'sanctum');
        $this->branch = $this->makeBranch([
            'tenant_id' => $this->tenant->id,
            'currency' => 'INR',
            'order_types' => ['takeaway'],
            'payment_methods' => ['cash'],
        ]);
        $this->menu = $this->makeMenu($this->branch);
        $this->onlineMenu = OnlineMenu::query()->create([
            'name' => 'Outlet',
            'slug' => 'outlet-'.$this->branch->id,
            'branch_id' => $this->branch->id,
            'menu_id' => $this->menu->id,
            'is_active' => true,
        ]);
        $this->cartId = (string) Str::uuid();

        [$this->product, $this->size, $this->addons] = $this->makeCustomisableProduct();
    }

    // ------------------------------------------------------------- helpers

    private function makeTenant(string $slug): Tenant
    {
        $tenant = Tenant::query()->withoutGlobalScopes()->create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'domain' => "$slug.example.test",
            'is_active' => true,
        ]);

        $planId = DB::table('subscription_plans')->insertGetId([
            'name' => 'Customer '.$slug,
            'code' => 'customer-'.$slug,
            'features' => json_encode(['customer_app', 'pos', 'online_ordering']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_subscriptions')->insert([
            'tenant_id' => $tenant->id,
            'subscription_plan_id' => $planId,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenant->refresh();
    }

    private function issueAppSession(Tenant $tenant): string
    {
        $registration = CustomerAppRegistration::query()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'package_id' => 'com.nexdine.'.$tenant->slug,
            'display_name' => $tenant->name,
            'platform' => 'android',
            'status' => 'active',
            'branding_revision' => 1,
        ]);

        $plain = Str::random(64);
        DB::table('customer_app_sessions')->insert([
            'uuid' => (string) Str::uuid(),
            'customer_app_registration_id' => $registration->id,
            'tenant_id' => $tenant->id,
            'installation_id' => (string) Str::uuid(),
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $plain;
    }

    /** @return array{0: Product, 1: Option, 2: Option} */
    private function makeCustomisableProduct(): array
    {
        $product = Product::query()->create([
            'name' => 'Biryani',
            'menu_id' => $this->menu->id,
            'price' => 200,
            'is_active' => true,
            'is_available' => true,
            'food_type' => 'non_veg',
        ]);
        $category = Category::query()->create([
            'name' => 'Mains',
            'menu_id' => $this->menu->id,
            'is_active' => true,
        ]);
        $product->categories()->attach($category->id);
        // Products carry no branch_id column; the branch comes via the menu.

        // Required single choice: the case that used to be ordered wrong.
        $size = Option::query()->create([
            'name' => 'Size',
            'type' => 'radio',
            'is_required' => true,
            'branch_id' => $this->branch->id,
        ]);
        OptionValue::query()->create([
            'label' => 'Half',
            'option_id' => $size->id,
            'price_type' => PriceType::Fixed,
            'price' => 0,
            'branch_id' => $this->branch->id,
        ]);
        $full = OptionValue::query()->create([
            'label' => 'Full',
            'option_id' => $size->id,
            'price_type' => PriceType::Fixed,
            'price' => 120,
            'branch_id' => $this->branch->id,
        ]);

        // Optional multi choice.
        $addons = Option::query()->create([
            'name' => 'Add ons',
            'type' => 'checkbox',
            'is_required' => false,
            'branch_id' => $this->branch->id,
        ]);
        OptionValue::query()->create([
            'label' => 'Raita',
            'option_id' => $addons->id,
            'price_type' => PriceType::Fixed,
            'price' => 30,
            'branch_id' => $this->branch->id,
        ]);

        $product->options()->attach([$size->id, $addons->id]);

        return [$product->refresh(), $size->refresh()->load('values'), $addons->refresh()->load('values')];
    }

    private function headers(array $extra = []): array
    {
        return ['X-NexDine-Customer-App-Token' => $this->appToken, ...$extra];
    }

    private function seedCart(array $options): void
    {
        $this->postJson(
            "/api/v1/customer-app/cart/{$this->cartId}/initialize",
            ['branch_id' => $this->branch->id, 'order_type' => 'takeaway'],
            $this->headers(),
        )->assertOk();

        $this->postJson(
            "/api/v1/customer-app/cart/{$this->cartId}/items/batch",
            [
                'branch_id' => $this->branch->id,
                'items' => [
                    ['product_id' => $this->product->id, 'qty' => 2, 'options' => $options],
                ],
            ],
            $this->headers(),
        )->assertOk();
    }

    private function placeOrder()
    {
        return $this->postJson(
            "/api/v1/customer-app/orders/{$this->cartId}",
            [
                'branch_id' => $this->branch->id,
                'menu_id' => $this->menu->id,
                'type' => 'takeaway',
                'customer_name' => 'Test Diner',
                'customer_mobile' => '9876543210',
            ],
            $this->headers(['Idempotency-Key' => (string) Str::uuid()]),
        );
    }

    private function fullValue(): OptionValue
    {
        return $this->size->values->firstWhere('label', 'Full');
    }

    private function raitaValue(): OptionValue
    {
        return $this->addons->values->firstWhere('label', 'Raita');
    }

    // --------------------------------------------------------------- tests

    public function test_a_required_single_choice_reaches_the_order(): void
    {
        // Radio groups are validated against a scalar id, which is exactly the
        // shape CartLine::optionsPayload() now produces.
        $this->seedCart([$this->size->id => $this->fullValue()->id]);
        $this->placeOrder()->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();
        $item = $order->products()->firstOrFail();

        $this->assertTrue($item->options->isNotEmpty());
        $this->assertSame(
            $this->size->id,
            (int) $item->options->first()->option_id,
        );
    }

    public function test_the_option_surcharge_is_reflected_in_the_order_total(): void
    {
        $this->seedCart([
            $this->size->id => $this->fullValue()->id,
            $this->addons->id => [$this->raitaValue()->id],
        ]);
        $this->placeOrder()->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();

        // (200 base + 120 full + 30 raita) x 2
        $this->assertEqualsWithDelta(700, (float) $order->subtotal->amount(), 0.01);
    }

    public function test_a_multi_choice_group_keeps_every_selection(): void
    {
        $this->seedCart([
            $this->size->id => $this->fullValue()->id,
            $this->addons->id => [$this->raitaValue()->id],
        ]);
        $this->placeOrder()->assertCreated();

        $item = Order::query()->latest('id')->firstOrFail()->products()->firstOrFail();

        $this->assertEqualsCanonicalizing(
            [$this->size->id, $this->addons->id],
            $item->options->pluck('option_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_omitting_a_required_group_is_rejected(): void
    {
        // The old client always sent an empty options map; the server must
        // refuse it rather than silently accept a mis-configured item.
        $this->postJson(
            "/api/v1/customer-app/cart/{$this->cartId}/initialize",
            ['branch_id' => $this->branch->id, 'order_type' => 'takeaway'],
            $this->headers(),
        )->assertOk();

        $this->postJson(
            "/api/v1/customer-app/cart/{$this->cartId}/items/batch",
            [
                'branch_id' => $this->branch->id,
                'items' => [
                    ['product_id' => $this->product->id, 'qty' => 1, 'options' => []],
                ],
            ],
            $this->headers(),
        )->assertStatus(422);
    }

    public function test_an_unknown_option_value_is_rejected(): void
    {
        $this->postJson(
            "/api/v1/customer-app/cart/{$this->cartId}/initialize",
            ['branch_id' => $this->branch->id, 'order_type' => 'takeaway'],
            $this->headers(),
        )->assertOk();

        $this->postJson(
            "/api/v1/customer-app/cart/{$this->cartId}/items/batch",
            [
                'branch_id' => $this->branch->id,
                'items' => [
                    ['product_id' => $this->product->id, 'qty' => 1, 'options' => [
                        $this->size->id => 999999,
                    ]],
                ],
            ],
            $this->headers(),
        )->assertStatus(422);
    }

    public function test_food_type_is_exposed_on_the_customer_menu(): void
    {
        $payload = $this->getJson(
            "/api/v1/customer-app/online-menus/{$this->onlineMenu->slug}/menu",
            $this->headers(),
        )->assertOk();

        $product = collect($payload->json('body.products'))
            ->firstWhere('id', $this->product->id);

        $this->assertSame('non_veg', $product['food_type']);
        $this->assertTrue($product['has_options']);
    }
}
