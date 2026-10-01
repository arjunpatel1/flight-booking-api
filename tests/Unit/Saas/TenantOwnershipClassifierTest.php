<?php

namespace Tests\Unit\Saas;

use Modules\Saas\Services\Tenant\TenantOwnershipClassifier;
use PHPUnit\Framework\TestCase;

class TenantOwnershipClassifierTest extends TestCase
{
    public function test_it_classifies_database_ownership_from_schema_columns(): void
    {
        $classifier = new TenantOwnershipClassifier();

        $this->assertSame('tenant_branch', $classifier->classify(['id', 'tenant_id', 'branch_id']));
        $this->assertSame('tenant', $classifier->classify([['name' => 'id'], ['name' => 'tenant_id']]));
        $this->assertSame('branch', $classifier->classify([(object) ['name' => 'branch_id']]));
        $this->assertSame('global_or_reference', $classifier->classify(['id', 'name']));
    }
}
