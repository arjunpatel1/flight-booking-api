<?php

namespace Tests\Feature\Saas;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Modules\Branch\Models\Branch;
use Modules\Cart\Support\PublicTenantGuard;
use Modules\Inventory\Models\Ingredient;
use Modules\Saas\Models\Tenant;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\Permission;
use Modules\User\Models\Role;
use Modules\User\Models\User;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_cart_branch_resolution_rejects_another_tenants_branch(): void
    {
        $tenantA = Tenant::query()->create(['name' => 'Cart Tenant A', 'slug' => 'cart-tenant-a', 'is_active' => true]);
        $tenantB = Tenant::query()->create(['name' => 'Cart Tenant B', 'slug' => 'cart-tenant-b', 'is_active' => true]);
        $branchA = Branch::factory()->create(['tenant_id' => $tenantA->id]);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id]);
        $request = Request::create('/api/v1/public/cart/items', 'POST');
        $request->attributes->set('tenant_id', $tenantA->id);

        $this->assertSame($branchA->id, PublicTenantGuard::branch($request, $branchA->id)->id);

        $this->expectException(ModelNotFoundException::class);
        PublicTenantGuard::branch($request, $branchB->id);
    }

    public function test_tenant_user_only_sees_branches_and_branch_models_for_their_tenant(): void
    {
        $tenantA = Tenant::query()->create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'is_active' => true,
        ]);
        $tenantB = Tenant::query()->create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
            'is_active' => true,
        ]);

        $branchA = Branch::factory()->create(['tenant_id' => $tenantA->id]);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id]);

        Ingredient::factory()->create(['branch_id' => $branchA->id]);
        Ingredient::factory()->create(['branch_id' => $branchB->id]);

        $tenantUser = User::factory()->create([
            'tenant_id' => $tenantA->id,
            'branch_id' => null,
        ]);

        Sanctum::actingAs($tenantUser, ['*'], 'api');

        $this->assertSame([$branchA->id], Branch::query()->pluck('id')->all());
        $this->assertSame([$branchA->id], Ingredient::query()->pluck('branch_id')->all());
    }

    public function test_enterprise_admin_sees_all_own_tenant_branches_but_never_another_tenant(): void
    {
        $tenantA = Tenant::query()->create(['name' => 'Enterprise A', 'slug' => 'enterprise-a', 'is_active' => true]);
        $tenantB = Tenant::query()->create(['name' => 'Enterprise B', 'slug' => 'enterprise-b', 'is_active' => true]);
        $branchA1 = Branch::factory()->create(['tenant_id' => $tenantA->id]);
        $branchA2 = Branch::factory()->create(['tenant_id' => $tenantA->id]);
        Branch::factory()->create(['tenant_id' => $tenantB->id]);
        $role = Role::query()->create([
            'name' => DefaultRole::EnterpriseAdmin->value,
            'display_name' => ['en' => 'Enterprise administrator'],
            'guard_name' => 'api',
            'built_in' => true,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenantA->id, 'branch_id' => null]);
        $user->roles()->attach($role);

        Sanctum::actingAs($user, ['*'], 'api');

        $this->assertSame(
            [$branchA1->id, $branchA2->id],
            Branch::query()->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame('tenant', $user->accessScope());
    }

    public function test_effective_permissions_are_the_union_of_every_assigned_role(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Permission Tenant', 'slug' => 'permission-tenant', 'is_active' => true]);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager = Role::query()->create(['name' => 'manager', 'display_name' => ['en' => 'Manager'], 'guard_name' => 'api']);
        $waiter = Role::query()->create(['name' => 'waiter', 'display_name' => ['en' => 'Waiter'], 'guard_name' => 'api']);
        $orders = Permission::query()->create(['name' => 'admin.orders.index', 'guard_name' => 'api']);
        $pos = Permission::query()->create(['name' => 'admin.pos.index', 'guard_name' => 'api']);
        $manager->permissions()->attach($orders);
        $waiter->permissions()->attach($pos);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        $user->roles()->attach([$manager->id, $waiter->id]);

        $this->assertSame(
            ['admin.orders.index', 'admin.pos.index'],
            $user->getEffectivePermissions(),
        );
        $this->assertSame('branch', $user->accessScope());
    }

    public function test_users_created_in_a_tenant_portal_inherit_tenant_ownership(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Owned Users',
            'slug' => 'owned-users',
            'is_active' => true,
        ]);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => null]);

        Sanctum::actingAs($owner, ['*'], 'api');

        $tenantWide = User::factory()->create(['tenant_id' => null, 'branch_id' => null]);
        $branchUser = User::factory()->create(['tenant_id' => null, 'branch_id' => $branch->id]);

        $this->assertSame($tenant->id, $tenantWide->tenant_id);
        $this->assertSame($tenant->id, $branchUser->tenant_id);
    }
}
