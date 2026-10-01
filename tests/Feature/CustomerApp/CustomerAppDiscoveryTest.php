<?php

namespace Tests\Feature\CustomerApp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Menu\Models\OnlineMenu;
use Modules\Saas\Models\CustomerAppRegistration;
use Modules\Saas\Models\Tenant;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

/**
 * Contract for GET /api/v1/customer-app/restaurants — the discovery feed.
 *
 * Guards the fields that replaced the hardcoded `is_open => true` and the
 * unvalidated customer_app_settings JSON.
 */
class CustomerAppDiscoveryTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/customer-app/restaurants';

    private Tenant $tenant;
    private string $appToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();

        $this->tenant = $this->makeTenant('acme');
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

    /** An outlet is an active online menu attached to an active branch. */
    private function makeOutlet(array $branchOverrides = [], ?Tenant $tenant = null): Branch
    {
        $branch = $this->makeBranch([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            ...$branchOverrides,
        ]);

        OnlineMenu::query()->create([
            'name' => 'Outlet '.$branch->id,
            'slug' => 'outlet-'.$branch->id,
            'branch_id' => $branch->id,
            'menu_id' => $this->makeMenu($branch)->id,
            'is_active' => true,
        ]);

        return $branch;
    }

    /** @return array<string, list<array{open: string, close: string}>> */
    private function everyDay(string $open, string $close): array
    {
        return array_fill_keys(
            ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
            [['open' => $open, 'close' => $close]],
        );
    }

    private function fetch(string $query = '')
    {
        return $this->getJson(self::ENDPOINT.$query, [
            'X-NexDine-Customer-App-Token' => $this->appToken,
        ]);
    }

    // --------------------------------------------------------------- tests

    public function test_it_exposes_the_new_outlet_profile_fields(): void
    {
        $this->makeOutlet([
            'cuisines' => ['North Indian', 'Chinese'],
            'delivery_eta_minutes' => 32,
            'price_for_two' => 500,
            'timezone' => 'Asia/Kolkata',
            'opening_hours' => $this->everyDay('00:00', '23:59'),
        ]);

        $outlet = $this->fetch()->assertOk()->json('body.restaurants.0');

        $this->assertSame(['North Indian', 'Chinese'], $outlet['cuisines']);
        $this->assertSame(32, $outlet['delivery_eta_minutes']);
        $this->assertEquals(500, $outlet['price_for_two']);
        $this->assertTrue($outlet['is_open']);
        $this->assertArrayHasKey('cover_image_url', $outlet);
        $this->assertArrayHasKey('distance_km', $outlet);
    }

    public function test_a_branch_without_a_schedule_stays_open(): void
    {
        $this->makeOutlet(['opening_hours' => null]);

        $this->assertTrue($this->fetch()->json('body.restaurants.0.is_open'));
    }

    public function test_the_manual_kill_switch_closes_an_outlet(): void
    {
        $this->makeOutlet([
            'is_accepting_orders' => false,
            'timezone' => 'Asia/Kolkata',
            'opening_hours' => $this->everyDay('00:00', '23:59'),
        ]);

        $outlet = $this->fetch()->json('body.restaurants.0');

        $this->assertFalse($outlet['is_open']);
        $this->assertSame('00:00', $outlet['opens_at']);
    }

    public function test_legacy_settings_json_still_supplies_cuisines_and_offers(): void
    {
        // A tenant that only ever filled in the old settings blob must not
        // regress now that branch columns are authoritative.
        $branch = $this->makeOutlet();
        DB::table('customer_app_settings')->insert([
            'tenant_id' => $this->tenant->id,
            'settings' => json_encode([
                'branch_cuisines' => [(string) $branch->id => ['Biryani']],
                'branch_offers' => [(string) $branch->id => '20% off'],
                'delivery_eta_minutes' => 45,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $outlet = $this->fetch()->json('body.restaurants.0');

        $this->assertSame(['Biryani'], $outlet['cuisines']);
        $this->assertSame('20% off', $outlet['offer_label']);
        $this->assertSame(45, $outlet['delivery_eta_minutes']);
    }

    public function test_branch_columns_win_over_the_legacy_settings_blob(): void
    {
        $branch = $this->makeOutlet([
            'cuisines' => ['Thai'],
            'delivery_eta_minutes' => 20,
        ]);
        DB::table('customer_app_settings')->insert([
            'tenant_id' => $this->tenant->id,
            'settings' => json_encode([
                'branch_cuisines' => [(string) $branch->id => ['Biryani']],
                'delivery_eta_minutes' => 45,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $outlet = $this->fetch()->json('body.restaurants.0');

        $this->assertSame(['Thai'], $outlet['cuisines']);
        $this->assertSame(20, $outlet['delivery_eta_minutes']);
    }

    public function test_it_computes_distance_and_sorts_nearest_first(): void
    {
        // Rajkot, then Ahmedabad ~200km away.
        $near = $this->makeOutlet(['latitude' => 22.3039, 'longitude' => 70.8022]);
        $far = $this->makeOutlet(['latitude' => 23.0225, 'longitude' => 72.5714]);

        $outlets = $this->fetch('?lat=22.3039&lng=70.8022')->json('body.restaurants');

        $this->assertSame($near->id, $outlets[0]['branch_id']);
        $this->assertSame($far->id, $outlets[1]['branch_id']);
        $this->assertEqualsWithDelta(0, $outlets[0]['distance_km'], 0.5);
        $this->assertGreaterThan(150, $outlets[1]['distance_km']);
    }

    public function test_distance_is_null_when_the_caller_sends_no_position(): void
    {
        $this->makeOutlet(['latitude' => 22.3039, 'longitude' => 70.8022]);

        $this->assertNull($this->fetch()->json('body.restaurants.0.distance_km'));
    }

    public function test_a_malformed_position_is_ignored_rather_than_failing(): void
    {
        $this->makeOutlet(['latitude' => 22.3039, 'longitude' => 70.8022]);

        $response = $this->fetch('?lat=999&lng=abc')->assertOk();

        $this->assertNull($response->json('body.restaurants.0.distance_km'));
    }

    public function test_it_only_lists_outlets_belonging_to_the_session_tenant(): void
    {
        $mine = $this->makeOutlet();
        $this->makeOutlet(tenant: $this->makeTenant('rival'));

        $response = $this->fetch()->assertOk();

        $this->assertSame(1, $response->json('body.outlet_count'));
        $this->assertSame($mine->id, $response->json('body.restaurants.0.branch_id'));
    }
}
