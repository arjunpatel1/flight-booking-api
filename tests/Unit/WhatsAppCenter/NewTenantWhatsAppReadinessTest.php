<?php

namespace Tests\Unit\WhatsAppCenter;

use PHPUnit\Framework\TestCase;

class NewTenantWhatsAppReadinessTest extends TestCase
{
    public function test_system_starter_tenants_receive_safe_whatsapp_ordering_defaults(): void
    {
        $config = file_get_contents(__DIR__.'/../../../Modules/Saas/config/config.php');
        $provisioning = file_get_contents(__DIR__.'/../../../Modules/Saas/app/Services/Provisioning/SaasProvisioningService.php');

        $this->assertStringContainsString("'whatsapp_ordering'", $config);
        $this->assertStringContainsString("'whatsapp_managed_api'", $config);
        $this->assertStringContainsString("'whatsapp_human_handoff'", $config);
        $this->assertStringContainsString('DefaultRole::AdminBranch', $provisioning);
        $this->assertStringContainsString("'whatsapp_order_greeting_keywords'", $provisioning);
        $this->assertStringContainsString("'online_order_kot_release_policy' => 'after_payment_or_approval'", $provisioning);
    }

    public function test_saas_assignment_uses_server_side_tenant_readiness(): void
    {
        $controller = file_get_contents(__DIR__.'/../../../Modules/Saas/app/Http/Controllers/Api/V1/SaasWhatsAppOrderingController.php');

        $this->assertStringContainsString('tenantReadiness', $controller);
        $this->assertStringContainsString("'ready_for_assignment'", $controller);
        $this->assertStringContainsString("whereNull('deleted_at')", $controller);
        $this->assertStringContainsString("'tenant_id' => implode(' ', \$readiness['issues'])", $controller);
        $this->assertStringContainsString('array_replace($this->defaultCapabilities()', $controller);
    }
}
