<?php

namespace Tests\Unit\Saas;

use Modules\Saas\Support\ProvisioningStatus;
use Modules\Saas\Support\ProvisioningWorkflow;
use Tests\TestCase;

class ProvisioningWorkflowTest extends TestCase
{
    public function test_workflow_defines_retry_safe_step_metadata(): void
    {
        $steps = ProvisioningWorkflow::initialSteps();

        $this->assertArrayHasKey('tenant_created', $steps);
        $this->assertArrayHasKey('client_config_ready', $steps);
        $this->assertSame(ProvisioningStatus::CLIENT_CONFIG_READY, $steps['client_config_ready']['state']);
        $this->assertSame('pending', $steps['storage_ready']['status']);
        $this->assertSame(0, $steps['storage_ready']['retry_count']);
        $this->assertSame([], $steps['storage_ready']['logs']);
    }

    public function test_completed_steps_are_excluded_from_resume_dispatch(): void
    {
        $steps = ProvisioningWorkflow::initialSteps();
        $steps['storage_ready']['status'] = 'completed';
        $steps['demo_data_ready']['status'] = 'failed';

        $pending = ProvisioningWorkflow::pendingRunnableSteps($steps);

        $this->assertNotContains('storage_ready', $pending);
        $this->assertContains('demo_data_ready', $pending);
        $this->assertContains('completed', $pending);
    }
}
