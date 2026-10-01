<?php

namespace Tests\Unit\User;

use Modules\User\Facades\Permission;
use Tests\TestCase;

class PermissionRegistryTest extends TestCase
{
    public function test_waiter_operations_are_registered_permissions(): void
    {
        $permissions = Permission::getPermissionNames();

        $this->assertContains('admin.pos_sessions.index', $permissions);
        $this->assertContains('admin.pos_cash_movements.index', $permissions);
        $this->assertContains('admin.expenses.index', $permissions);
        $this->assertContains('admin.waiter_settlements.index', $permissions);
    }
}
