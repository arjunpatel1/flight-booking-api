<?php

namespace Tests\Unit\User;

use Modules\User\Models\User;
use PHPUnit\Framework\TestCase;

class UserPartialAttributeSafetyTest extends TestCase
{
    public function test_tenant_and_branch_helpers_are_safe_for_partial_selects(): void
    {
        $partial = new User(['name' => 'Partial user']);

        $this->assertNull($partial->tenantId());
        $this->assertNull($partial->branchId());
        $this->assertFalse($partial->assignedToTenant());
        $this->assertFalse($partial->assignedToBranch());

        $scoped = new User([
            'name' => 'Scoped user',
            'tenant_id' => 14,
            'branch_id' => 29,
        ]);

        $this->assertSame(14, $scoped->tenantId());
        $this->assertSame(29, $scoped->branchId());
        $this->assertTrue($scoped->assignedToTenant());
        $this->assertTrue($scoped->assignedToBranch());
    }
}
