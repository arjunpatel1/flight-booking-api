<?php

namespace Tests\Unit\Saas;

use Modules\Saas\Models\Tenant;
use Modules\Saas\Support\TenantContext;
use PHPUnit\Framework\TestCase;

class TenantContextTest extends TestCase
{
    public function test_context_can_be_cleared_between_long_running_tasks(): void
    {
        $context = new TenantContext();
        $tenant = new Tenant(['name' => 'Scoped tenant']);
        $tenant->id = 91;

        $context->set($tenant);
        $this->assertSame(91, $context->id());

        $context->clear();
        $this->assertFalse($context->hasTenant());
        $this->assertNull($context->id());
    }
}
