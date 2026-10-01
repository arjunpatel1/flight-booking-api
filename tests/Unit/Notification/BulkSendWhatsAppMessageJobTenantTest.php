<?php

namespace Tests\Unit\Notification;

use Modules\Notification\Jobs\BulkSendWhatsAppMessageJob;
use Tests\TestCase;

class BulkSendWhatsAppMessageJobTenantTest extends TestCase
{
    public function test_campaign_context_survives_queue_serialization(): void
    {
        $job = new BulkSendWhatsAppMessageJob(
            audience: 'customers',
            template: 'promotion',
            campaignId: 'campaign-1',
            tenantId: 41,
            branchId: 73,
        );

        /** @var BulkSendWhatsAppMessageJob $restored */
        $restored = unserialize(serialize($job));

        $this->assertSame(41, $restored->tenantId);
        $this->assertSame(73, $restored->branchId);
        $this->assertSame('campaign-1', $restored->campaignId);
    }

    public function test_unscoped_worker_execution_fails_closed(): void
    {
        $job = new BulkSendWhatsAppMessageJob(
            audience: 'customers',
            template: 'promotion',
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('tenant-bound audience');

        $job->handle();
    }
}
