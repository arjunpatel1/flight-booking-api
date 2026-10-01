<?php

namespace Tests\Unit\Pos;

use Tests\TestCase;

class WaiterAssistantRiskSettingTest extends TestCase
{
    public function test_tenant_setting_can_disable_pos_risk_cards_without_disabling_monitoring(): void
    {
        $assistant = file_get_contents(base_path('Modules/Pos/app/Services/PosViewer/Concerns/HandlesWaiterAssistant.php'));
        $request = file_get_contents(base_path('Modules/Setting/app/Http/Requests/Api/V1/SaveSettingRequest.php'));
        $settings = file_get_contents(base_path('Modules/Setting/app/Services/Setting/SettingService.php'));

        $this->assertStringContainsString("setting('pos_risk_notifications_enabled', true)", $assistant);
        $this->assertStringContainsString("'notifications_enabled' => false", $assistant);
        $this->assertStringContainsString('"pos_risk_notifications_enabled" => "required|boolean"', $request);
        $this->assertStringContainsString("'pos_risk_notifications_enabled' => \$settings['pos_risk_notifications_enabled'] ?? true", $settings);
    }
}
