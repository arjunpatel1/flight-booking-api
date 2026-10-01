<?php

namespace Modules\Aggregator\Tests\Unit;

use Modules\Aggregator\Models\PartnerApiCredential;
use Modules\Aggregator\Models\PartnerApiIntegration;
use Modules\Aggregator\Support\PartnerContext;
use Tests\TestCase;

class PartnerContextTest extends TestCase
{
    public function test_it_enforces_scopes_and_branch_allowlists_without_database_ids_leaking(): void
    {
        $credential = new PartnerApiCredential([
            'tenant_id' => 42,
            'scopes' => ['catalog:read'],
            'branch_ids' => [7, 9],
        ]);
        $context = new PartnerContext($credential, new PartnerApiIntegration);

        $this->assertSame(42, $context->tenantId());
        $this->assertTrue($context->hasScope('catalog:read'));
        $this->assertFalse($context->hasScope('orders:write'));
        $this->assertTrue($context->canAccessBranch(7));
        $this->assertFalse($context->canAccessBranch(8));
    }

    public function test_empty_branch_allowlist_means_all_branches_in_the_resolved_tenant_only(): void
    {
        $credential = new PartnerApiCredential(['tenant_id' => 42, 'scopes' => ['*'], 'branch_ids' => []]);
        $context = new PartnerContext($credential, new PartnerApiIntegration);

        $this->assertTrue($context->hasScope('orders:write'));
        $this->assertTrue($context->canAccessBranch(999));
    }

    public function test_order_write_scope_can_read_back_scoped_orders(): void
    {
        $credential = new PartnerApiCredential([
            'tenant_id' => 42,
            'scopes' => ['orders:write'],
            'branch_ids' => [7],
        ]);
        $context = new PartnerContext($credential, new PartnerApiIntegration);

        $this->assertTrue($context->hasScope('orders:write'));
        $this->assertTrue($context->hasScope('orders:read'));
        $this->assertFalse($context->hasScope('catalog:read'));
        $this->assertTrue($context->canAccessBranch(7));
        $this->assertFalse($context->canAccessBranch(8));
    }
}
