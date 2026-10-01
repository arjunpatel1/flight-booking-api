<?php

namespace Tests\Feature\CustomerApp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Saas\Models\CustomerAppRegistration;
use Modules\Saas\Models\Tenant;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\Role;
use Modules\User\Models\User;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

/**
 * Contract for GET /api/v1/customer-app/orders, the endpoint that replaced
 * SharedPreferences-only order history in the customer app.
 */
class CustomerAppOrderHistoryTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/customer-app/orders';

    private Tenant $tenant;
    private Tenant $otherTenant;
    private Branch $branch;
    private Branch $otherBranch;
    private User $customer;
    private string $appToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();

        $this->tenant = $this->makeTenant('acme');
        $this->otherTenant = $this->makeTenant('rival');

        $this->branch = $this->makeBranch(['tenant_id' => $this->tenant->id]);
        $this->otherBranch = $this->makeBranch(['tenant_id' => $this->otherTenant->id]);

        $this->customer = $this->makeCustomer($this->tenant);
        $this->appToken = $this->issueAppSession($this->tenant);
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

    private function makeCustomer(Tenant $tenant): User
    {
        $user = User::query()->create([
            'name' => 'Test Diner',
            'username' => 'customer_'.$tenant->id.'_'.Str::random(6),
            'email' => Str::random(8).'@example.test',
            'password' => bcrypt('secret-Passw0rd'),
            'is_active' => true,
            'can_login' => true,
        ]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();
        // permission:sync-permissions seeds permissions, not roles.
        Role::findOrCreate(DefaultRole::Customer->value, 'api');
        $user->assignRole(DefaultRole::Customer->value);

        return $user->refresh();
    }

    /** Mint a signed app session the way the bootstrap/session exchange does. */
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

    private function fetch(?string $token = null, ?User $as = null)
    {
        if ($as) {
            $this->actingAs($as, 'sanctum');
        }

        return $this->getJson(self::ENDPOINT, [
            'X-NexDine-Customer-App-Token' => $token ?? $this->appToken,
        ]);
    }

    // --------------------------------------------------------------- tests

    public function test_it_returns_the_signed_in_customers_orders(): void
    {
        $order = $this->makeOrder($this->branch, [
            'customer_id' => $this->customer->id,
            'status' => OrderStatus::Preparing,
            'type' => OrderType::Delivery,
            'total' => 450,
        ]);

        $response = $this->fetch(as: $this->customer)->assertOk();

        $data = $response->json('body.data');
        $this->assertCount(1, $data);
        $this->assertSame($order->reference_no, $data[0]['reference_no']);
        $this->assertSame('delivery', $data[0]['order_type']);
        $this->assertSame($this->branch->uuid, $data[0]['branch']['reference']);
        $this->assertArrayNotHasKey('id', $data[0]['branch']);
    }

    public function test_it_never_leaks_another_customers_orders(): void
    {
        $stranger = $this->makeCustomer($this->tenant);
        $this->makeOrder($this->branch, ['customer_id' => $stranger->id]);

        $this->fetch(as: $this->customer)->assertOk()->assertJsonCount(0, 'body.data');
    }

    public function test_it_never_leaks_orders_from_another_tenant(): void
    {
        // Same person, an order placed against a different tenant's branch.
        $this->makeOrder($this->otherBranch, ['customer_id' => $this->customer->id]);
        $this->makeOrder($this->branch, ['customer_id' => $this->customer->id]);

        $response = $this->fetch(as: $this->customer)->assertOk();

        $this->assertCount(1, $response->json('body.data'));
        $this->assertSame(
            $this->branch->uuid,
            $response->json('body.data.0.branch.reference'),
        );
    }

    public function test_it_returns_newest_orders_first(): void
    {
        $older = $this->makeOrder($this->branch, [
            'customer_id' => $this->customer->id,
            'order_date' => now()->subDays(3),
        ]);
        $newer = $this->makeOrder($this->branch, [
            'customer_id' => $this->customer->id,
            'order_date' => now(),
        ]);

        $references = collect($this->fetch(as: $this->customer)->json('body.data'))
            ->pluck('reference_no')
            ->all();

        $this->assertSame([$newer->reference_no, $older->reference_no], $references);
    }

    public function test_a_guest_without_a_sanctum_token_is_rejected(): void
    {
        $this->makeOrder($this->branch, ['customer_id' => $this->customer->id]);

        $this->fetch()->assertUnauthorized();
    }

    public function test_a_request_without_an_app_session_is_rejected(): void
    {
        // ResolveCustomerAppContext treats an unknown/expired app session as
        // unauthenticated (401), not forbidden.
        $this->fetch(token: 'not-a-real-session', as: $this->customer)
            ->assertUnauthorized();
    }

    public function test_per_page_is_capped_so_history_cannot_be_dumped(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->makeOrder($this->branch, ['customer_id' => $this->customer->id]);
        }

        $this->actingAs($this->customer, 'sanctum');
        $response = $this->getJson(self::ENDPOINT.'?per_page=5000', [
            'X-NexDine-Customer-App-Token' => $this->appToken,
        ])->assertOk();

        $this->assertSame(50, $response->json('body.pagination.per_page'));
    }

    public function test_saved_edit_validation_is_read_only_and_scoped_to_customer_and_branch(): void
    {
        $order = $this->makeOrder($this->branch, ['customer_id' => $this->customer->id, 'status' => OrderStatus::Confirmed]);
        $this->actingAs($this->customer, 'sanctum');
        $path = '/api/v1/customer-app/orders/'.$order->reference_no.'/edit/'.Str::uuid();
        $response = $this->getJson($path.'?validate_only=1&branch_id='.$this->branch->id, [
            'X-NexDine-Customer-App-Token' => $this->appToken,
        ])->assertOk();
        $response->assertJsonPath('body.order.reference_no', $order->reference_no);
        $response->assertJsonPath('body.order.can_edit', true);
        $response->assertJsonMissingPath('body.cart');
        $this->assertSame($order->updated_at->toISOString(), $order->fresh()->updated_at->toISOString());
    }

    public function test_saved_edit_rejects_wrong_branch_missing_and_completed_orders(): void
    {
        $this->actingAs($this->customer, 'sanctum');
        $otherBranch = $this->makeBranch(['tenant_id' => $this->tenant->id]);
        $order = $this->makeOrder($otherBranch, ['customer_id' => $this->customer->id, 'status' => OrderStatus::Confirmed]);
        $headers = ['X-NexDine-Customer-App-Token' => $this->appToken];
        $path = '/api/v1/customer-app/orders/'.$order->reference_no.'/edit/'.Str::uuid();
        $this->getJson($path.'?validate_only=1&branch_id='.$this->branch->id, $headers)->assertNotFound();
        $order->update(['status' => OrderStatus::Completed]);
        $this->getJson($path.'?validate_only=1&branch_id='.$otherBranch->id, $headers)->assertUnprocessable();
        $order->delete();
        $this->getJson($path.'?validate_only=1&branch_id='.$otherBranch->id, $headers)->assertNotFound();
    }
}
