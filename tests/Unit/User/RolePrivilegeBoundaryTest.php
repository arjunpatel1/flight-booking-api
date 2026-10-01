<?php

namespace Tests\Unit\User;

use Modules\User\Models\User;
use Modules\User\Support\RolePermissionBoundary;
use Tests\TestCase;

class RolePrivilegeBoundaryTest extends TestCase
{
    public function test_tenant_role_delegation_excludes_platform_and_unowned_permissions(): void
    {
        $actor = new class extends User {
            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function assignedToTenant(): bool
            {
                return true;
            }

            public function assignedToBranch(): bool
            {
                return false;
            }

            public function getEffectivePermissions(): array
            {
                return ['admin.products.index', 'admin.saas.index', 'admin.orders.index'];
            }
        };

        $allowed = app(RolePermissionBoundary::class)->allowedNames($actor);

        $this->assertContains('admin.products.index', $allowed);
        $this->assertContains('admin.orders.index', $allowed);
        $this->assertNotContains('admin.saas.index', $allowed);
        $this->assertNotContains('admin.products.edit', $allowed);
    }

    public function test_role_visibility_and_mutation_apply_reference_role_protection(): void
    {
        $model = file_get_contents(base_path('Modules/User/app/Models/Role.php'));
        $service = file_get_contents(base_path('Modules/User/app/Services/Role/RoleService.php'));
        $roles = file_get_contents(base_path('Modules/User/app/Traits/HasRoles.php'));

        $this->assertStringContainsString("whereIn('name', DefaultRole::getBranchAvailableRoles())", $model);
        $this->assertStringContainsString('$this->assertMutable($role);', $service);
        $this->assertStringContainsString("\$actor->can('admin.saas.manage')", $service);
        $this->assertStringContainsString('&& $this->tenantId() === null', $roles);
        $this->assertStringContainsString('&& $this->branchId() === null', $roles);
    }
}
