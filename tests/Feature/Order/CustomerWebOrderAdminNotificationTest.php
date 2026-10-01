<?php

namespace Tests\Feature\Order;

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
use Modules\Notification\Models\Notification;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Listeners\NotifyTenantAdminsOfNewOrder;
use Modules\Order\Models\Order;
use Modules\Product\Models\Product;
use Modules\Saas\Models\CustomerAppRegistration;
use Modules\Saas\Models\Tenant;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\Permission;
use Modules\User\Models\Role;
use Modules\User\Models\User;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

/**
 * A customer places web/app orders while authenticated through the Sanctum
 * customer guard. Staff permissions live on the "api" guard, so the admin
 * notification listener must not evaluate staff access through the guard of
 * the current (customer) request.
 */
class CustomerWebOrderAdminNotificationTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    private Menu $menu;

    private Product $product;

    private string $appToken;

    private string $cartId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broadcasting.default' => 'null']);
        Queue::fake();
        $this->setUpAggregatorTestSupport();
        setting(['default_currency' => 'INR']);
        CurrencyRate::query()->create(['currency' => 'INR', 'rate' => 1]);

        $this->tenant = $this->makeTenant('notify');
        $this->appToken = $this->issueAppSession($this->tenant);
        $this->branch = $this->makeBranch([
            'tenant_id' => $this->tenant->id,
            'currency' => 'INR',
            'order_types' => ['takeaway'],
            'payment_methods' => ['cash'],
        ]);
        $this->menu = $this->makeMenu($this->branch);
        OnlineMenu::query()->create([
            'name' => 'Outlet', 'slug' => 'outlet-'.$this->branch->id,
            'branch_id' => $this->branch->id, 'menu_id' => $this->menu->id, 'is_active' => true,
        ]);
        $this->product = Product::query()->create([
            'name' => 'Paneer Tikka', 'menu_id' => $this->menu->id, 'price' => 250,
            'is_active' => true, 'is_available' => true, 'food_type' => 'veg',
        ]);
        $category = Category::query()->create(['name' => 'Starters', 'menu_id' => $this->menu->id, 'is_active' => true]);
        $this->product->categories()->attach($category->id);
        $this->cartId = (string) Str::uuid();
    }

    public function test_pending_customer_order_notifies_tenant_staff_with_order_access(): void
    {
        $manager = $this->makeStaff(['admin.orders.index']);
        $owner = $this->makeStaff([], DefaultRole::EnterpriseAdmin->value);
        $cashier = $this->makeStaff([]);
        $otherTenantManager = $this->makeStaff(['admin.orders.index'], null, $this->makeTenant('other'));

        $this->actingAsCustomer();
        $this->seedCart();
        $this->placeOrder()->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();
        // Pay-at-counter with the default release policy waits for staff:
        // this is exactly the order staff must be told about.
        $this->assertSame(OrderStatus::Pending, $order->status);

        $recipients = Notification::query()->where('type', 'order_created')
            ->where('action_url', "/admin/orders/{$order->id}/show")
            ->pluck('target_user_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame(collect([$manager->id, $owner->id])->sort()->values()->all(), $recipients);
        $this->assertNotContains($cashier->id, $recipients);
        $this->assertNotContains($otherTenantManager->id, $recipients);
        $this->assertSame('New Customer Web Order', Notification::query()
            ->where('target_user_id', $manager->id)->value('title'));

        app(NotifyTenantAdminsOfNewOrder::class)->handle(new OrderCreated($order->fresh(), true));
        $this->assertSame(2, Notification::query()->where('type', 'order_created')
            ->where('action_url', "/admin/orders/{$order->id}/show")->count());
    }

    private function makeStaff(array $permissions, ?string $role = null, ?Tenant $tenant = null): User
    {
        $user = User::query()->create([
            'name' => 'Staff '.Str::random(4),
            'username' => 'staff_'.Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => bcrypt('secret-Passw0rd'),
            'is_active' => true,
            'can_login' => true,
        ]);
        $user->forceFill(['tenant_id' => ($tenant ?? $this->tenant)->id])->saveQuietly();
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'api');
        }
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }
        if ($role) {
            Role::findOrCreate($role, 'api');
            $user->assignRole($role);
        }

        return $user->refresh();
    }

    private function actingAsCustomer(): void
    {
        $customer = User::query()->create([
            'name' => 'Web Diner',
            'username' => 'customer_'.Str::lower(Str::random(12)),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => bcrypt('secret-Passw0rd'),
            'is_active' => true,
            'can_login' => true,
        ]);
        $customer->forceFill(['tenant_id' => $this->tenant->id])->saveQuietly();
        Role::findOrCreate(DefaultRole::Customer->value, 'api');
        $customer->assignRole(DefaultRole::Customer->value);
        Sanctum::actingAs($customer, ['customer'], 'sanctum');
    }

    private function makeTenant(string $slug): Tenant
    {
        $tenant = Tenant::query()->withoutGlobalScopes()->create([
            'name' => ucfirst($slug), 'slug' => $slug, 'domain' => "$slug.example.test", 'is_active' => true,
        ]);
        $planId = DB::table('subscription_plans')->insertGetId([
            'name' => 'Customer '.$slug, 'code' => 'customer-'.$slug,
            'features' => json_encode(['customer_app', 'pos', 'online_ordering']),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tenant_subscriptions')->insert([
            'tenant_id' => $tenant->id, 'subscription_plan_id' => $planId, 'status' => 'active',
            'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $tenant->refresh();
    }

    private function issueAppSession(Tenant $tenant): string
    {
        $registration = CustomerAppRegistration::query()->create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'package_id' => 'com.nexdine.'.$tenant->slug,
            'display_name' => $tenant->name, 'platform' => 'android', 'status' => 'active', 'branding_revision' => 1,
        ]);
        $plain = Str::random(64);
        DB::table('customer_app_sessions')->insert([
            'uuid' => (string) Str::uuid(), 'customer_app_registration_id' => $registration->id,
            'tenant_id' => $tenant->id, 'installation_id' => (string) Str::uuid(),
            'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $plain;
    }

    private function seedCart(): void
    {
        $headers = ['X-NexDine-Customer-App-Token' => $this->appToken];
        $this->postJson("/api/v1/customer-app/cart/{$this->cartId}/initialize",
            ['branch_id' => $this->branch->id, 'order_type' => 'takeaway'], $headers)->assertOk();
        $this->postJson("/api/v1/customer-app/cart/{$this->cartId}/items/batch", [
            'branch_id' => $this->branch->id,
            'items' => [['product_id' => $this->product->id, 'qty' => 1, 'options' => []]],
        ], $headers)->assertOk();
    }

    private function placeOrder()
    {
        return $this->postJson("/api/v1/customer-app/orders/{$this->cartId}", [
            'branch_id' => $this->branch->id,
            'menu_id' => $this->menu->id,
            'type' => 'takeaway',
            'payment_method' => 'pay_at_counter',
            'customer_name' => 'Web Diner',
            'customer_mobile' => '9876543210',
        ], ['X-NexDine-Customer-App-Token' => $this->appToken, 'Idempotency-Key' => (string) Str::uuid()]);
    }
}
