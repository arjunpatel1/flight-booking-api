<?php

namespace Tests\Feature\User;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Saas\Models\Tenant;
use Modules\User\Models\Role;
use Modules\User\Models\User;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

class RoleTenantIsolationTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
        Cache::flush();
    }

    public function test_custom_role_lists_and_cache_are_tenant_isolated(): void
    {
        [$tenantA, $actorA] = $this->tenantActor('role-a');
        [$tenantB, $actorB] = $this->tenantActor('role-b');
        $roleA = $this->customRole('Tenant A Supervisor', $actorA);
        $roleB = $this->customRole('Tenant B Supervisor', $actorB);

        $this->actingAs($actorA, 'api');
        $idsA = Role::list(forBranch: true)->pluck('id');
        $this->assertTrue($idsA->contains($roleA->id));
        $this->assertFalse($idsA->contains($roleB->id));

        $this->actingAs($actorB, 'api');
        $idsB = Role::list(forBranch: true)->pluck('id');
        $this->assertTrue($idsB->contains($roleB->id));
        $this->assertFalse($idsB->contains($roleA->id));
    }

    public function test_employee_assignment_rejects_another_tenants_custom_role(): void
    {
        [$tenantA, $actorA] = $this->tenantActor('assign-a', ['admin.users.create']);
        [, $actorB] = $this->tenantActor('assign-b');
        $branchA = $this->makeBranch(['tenant_id' => $tenantA->id]);
        $foreignRole = $this->customRole('Foreign Supervisor', $actorB);
        $actorA->forceFill(['branch_id' => $branchA->id])->save();

        $this->actingAs($actorA->refresh(), 'api')
            ->postJson('/api/v1/users', [
                'tenant_id' => $tenantA->id,
                'branch_id' => $branchA->id,
                'roles' => [$foreignRole->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['roles']);
    }

    private function tenantActor(string $slug, array $permissions = []): array
    {
        $tenant = Tenant::query()->withoutGlobalScopes()->create([
            'name' => $slug,
            'slug' => $slug,
            'domain' => $slug.'.example.test',
            'is_active' => true,
        ]);
        $actor = $this->actingAsUserWithPermissions($permissions);
        $actor->forceFill(['tenant_id' => $tenant->id, 'branch_id' => null])->save();

        return [$tenant, $actor->refresh()];
    }

    private function customRole(string $name, User $creator): Role
    {
        return Role::query()->forceCreate([
            'display_name' => ['en' => $name],
            'name' => Role::getSlugName($name),
            'guard_name' => 'api',
            'built_in' => false,
            'created_by' => $creator->id,
        ]);
    }
}
