<?php

namespace Tests\Unit\Notification;

use Modules\Notification\Jobs\SendWhatsAppMessageJob;
use Modules\Notification\Services\WhatsApp\WhatsAppProviderFactory;
use Tests\TestCase;

class SendWhatsAppMessageJobTenantTest extends TestCase
{
    public function test_tenant_and_branch_survive_queue_serialization(): void
    {
        $job = new SendWhatsAppMessageJob('+919876543210', 'report_summary', [], [
            'tenant_id' => 41,
            'branch_id' => 73,
        ]);

        /** @var SendWhatsAppMessageJob $restored */
        $restored = unserialize(serialize($job));

        $this->assertSame(41, $restored->tenantId);
        $this->assertSame(73, $restored->branchId);
    }

    public function test_unscoped_message_fails_before_provider_or_database_access(): void
    {
        $job = new SendWhatsAppMessageJob('+919876543210', 'report_summary');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('tenant-bound WhatsApp job');

        $job->handle($this->createMock(WhatsAppProviderFactory::class));
    }

    public function test_sensitive_parameter_values_are_encrypted_for_retry_and_never_logged_in_plain_metadata(): void
    {
        $source = file_get_contents(base_path('Modules/Notification/app/Jobs/SendWhatsAppMessageJob.php'));
        $model = file_get_contents(base_path('Modules/Notification/app/Models/WhatsAppLog.php'));

        $this->assertStringContainsString("'parameter_keys' => array_keys(\$this->parameters)", $source);
        $this->assertStringContainsString("'recipient_fingerprint' => hash('sha256', \$this->recipient)", $source);
        $this->assertStringContainsString("'retry_payload' => ['parameters' => \$this->parameters]", $source);
        $this->assertStringContainsString("'retry_payload' => 'encrypted:array'", $model);
        $this->assertStringContainsString("protected \$hidden = [", $model);
        $this->assertStringContainsString("'retry_payload',", $model);
        $this->assertStringNotContainsString("'request_payload' => ['parameters' => \$this->parameters]", $source);
    }

    public function test_worker_binds_and_clears_tenant_settings_for_every_message(): void
    {
        $source = file_get_contents(base_path('Modules/Notification/app/Jobs/SendWhatsAppMessageJob.php'));

        $this->assertStringContainsString('$context->setId($this->tenantId);', $source);
        $this->assertStringContainsString('$settings->refreshSettingBinding();', $source);
        $this->assertStringContainsString('$context->clear();', $source);
        $this->assertStringContainsString('finally {', $source);
    }
}
