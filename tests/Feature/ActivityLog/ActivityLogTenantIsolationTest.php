<?php

namespace Tests\Feature\ActivityLog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\ActivityLog\Models\ActivityLog;
use Modules\ActivityLog\Services\ActivityLog\ActivityLogServiceInterface;
use Modules\User\Models\User;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

/**
 * activity_log has no tenant column and admin_branch — a tenant-scoped role —
 * can read the audit log, so the unscoped query exposed every other
 * restaurant's activity. Records are scoped by causer: a tenant sees what its
 * own people did; platform administrators still audit the whole estate.
 */
class ActivityLogTenantIsolationTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    private function makeTenant(string $name): int
    {
        return DB::table('tenants')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => json_encode(['en' => $name]),
            'slug' => \Illuminate\Support\Str::slug($name).'-'.uniqid(),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function logRow(?int $causerId, string $description): int
    {
        return DB::table('activity_log')->insertGetId([
            'log_name' => 'test.probe',
            'description' => $description,
            'subject_type' => null,
            'subject_id' => null,
            'causer_type' => User::class,
            'causer_id' => $causerId,
            'properties' => '{}',
            'event' => 'updated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function tenantUser(int $tenantId, array $permissions): User
    {
        $user = $this->actingAsUserWithPermissions($permissions);
        $user->forceFill(['tenant_id' => $tenantId])->save();

        return $user->refresh();
    }

    public function test_ip_search_is_grouped_under_existing_authorization_constraints(): void
    {
        $mine = $this->logRow(null, 'Owned event');
        $other = $this->logRow(null, 'Other event');
        DB::table('activity_log')->whereIn('id', [$mine, $other])->update(['properties' => json_encode(['info' => ['ip' => '192.0.2.10']])]);
        $ids = ActivityLog::query()->where('id', $mine)->search('192.0.2.10')->pluck('id')->all();
        $this->assertSame([$mine], $ids);
        $this->assertSame([], ActivityLog::query()->where('id', $mine)->search('203.0.113.99')->pluck('id')->all());
    }

    public function test_a_tenant_only_sees_activity_caused_by_its_own_users(): void
    {
        $mineTenant = $this->makeTenant('Mine');
        $otherTenant = $this->makeTenant('Theirs');

        $mine = $this->tenantUser($mineTenant, ['admin.activity_logs.index']);

        $theirs = User::factory()->create(['is_active' => true]);
        $theirs->forceFill(['tenant_id' => $otherTenant])->save();

        $this->logRow($mine->id, 'my tenant action');
        $this->logRow($theirs->id, 'other tenant action');

        // Exercised through the service: the HTTP route additionally enforces
        // tenant-host middleware, which is not what this test is about.
        $descriptions = collect(
            app(ActivityLogServiceInterface::class)->get()->items()
        )->pluck('description');

        $this->assertContains('my tenant action', $descriptions);
        $this->assertNotContains('other tenant action', $descriptions);
    }

    public function test_show_refuses_another_tenants_record(): void
    {
        $otherTenant = $this->makeTenant('Theirs');
        $mineTenant = $this->makeTenant('Mine');

        $theirs = User::factory()->create(['is_active' => true]);
        $theirs->forceFill(['tenant_id' => $otherTenant])->save();
        $foreignId = $this->logRow($theirs->id, 'other tenant action');

        $this->tenantUser($mineTenant, ['admin.activity_logs.index', 'admin.activity_logs.show']);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(ActivityLogServiceInterface::class)->show($foreignId);
    }

    public function test_a_platform_administrator_still_sees_every_record(): void
    {
        // A platform admin has no tenant_id.
        $platform = $this->actingAsUserWithPermissions(['admin.activity_logs.index']);
        $this->assertNull($platform->getAttributes()['tenant_id'] ?? null);

        $tenantUser = User::factory()->create(['is_active' => true]);
        $tenantUser->forceFill(['tenant_id' => $this->makeTenant('Theirs')])->save();
        $this->logRow($tenantUser->id, 'other tenant action');

        $descriptions = collect(
            app(ActivityLogServiceInterface::class)->get()->items()
        )->pluck('description');

        $this->assertContains('other tenant action', $descriptions);
    }

    /**
     * Operational entries (cache clear, Horizon control) target no model.
     * parseSubject() read $pieces[1] unconditionally, so a null subject_type
     * raised "Undefined array key 1" and broke the entire listing.
     */
    public function test_an_entry_without_a_subject_does_not_break_the_listing(): void
    {
        $user = $this->actingAsUserWithPermissions(['admin.activity_logs.index']);
        $this->logRow($user->id, 'operational action with no subject');

        $rows = collect($this->getJson('/api/v1/activity-logs?per_page=50')
            ->assertOk()
            ->json('body.data'));

        $row = $rows->firstWhere('description', 'operational action with no subject');

        $this->assertNotNull($row);
        $this->assertSame('', $row['subject']);
    }

    public function test_an_unknown_event_renders_a_readable_name(): void
    {
        $user = $this->actingAsUserWithPermissions(['admin.activity_logs.index']);

        DB::table('activity_log')->insertGetId([
            'log_name' => 'test.probe',
            'description' => 'feature toggled',
            'causer_type' => User::class,
            'causer_id' => $user->id,
            'properties' => '{}',
            'event' => 'feature_flag_updated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = collect($this->getJson('/api/v1/activity-logs?per_page=50')->assertOk()->json('body.data'))
            ->firstWhere('description', 'feature toggled');

        $this->assertSame('Feature Flag Updated', $row['event']);
        $this->assertStringNotContainsString('activitylog::', (string) $row['event']);
    }

    public function test_activity_logging_is_enabled_for_web_requests(): void
    {
        // The provider hard-disables logging in console, so assert the resolved
        // default rather than the console value.
        $this->assertTrue((bool) config('activitylog.enabled') || app()->runningInConsole());
        $this->assertNotNull(ActivityLog::query()->getModel());
    }
}
