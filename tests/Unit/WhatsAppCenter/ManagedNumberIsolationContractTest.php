<?php

namespace Tests\Unit\WhatsAppCenter;

use PHPUnit\Framework\TestCase;

class ManagedNumberIsolationContractTest extends TestCase
{
    public function test_saas_control_rejects_phone_and_normalized_display_number_reuse(): void
    {
        $controller = file_get_contents(__DIR__.'/../../../Modules/Saas/app/Http/Controllers/Api/V1/SaasWhatsAppOrderingController.php');
        $migration = file_get_contents(__DIR__.'/../../../Modules/WhatsAppCenter/database/migrations/2026_08_28_120100_enforce_unique_whatsapp_number_assignment.php');

        $this->assertStringContainsString('assertNumberAvailable', $controller);
        $this->assertStringContainsString("preg_replace('/\\D+/'", $controller);
        $this->assertStringContainsString('already assigned to {$conflictTenant}', $controller);
        $this->assertStringContainsString("unique('phone_number_id', 'wa_assignment_phone_unique')", $migration);
    }
}
