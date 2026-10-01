<?php

namespace Tests\Unit\Order;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DeliveryAdminNotificationContractTest extends TestCase
{
    #[Test]
    public function provider_failure_states_create_configurable_high_priority_admin_alerts(): void
    {
        $listener = file_get_contents(base_path('Modules/Order/app/Listeners/NotifyTenantAdminsOfDeliveryStatus.php'));
        $request = file_get_contents(base_path('Modules/Setting/app/Http/Requests/Api/V1/SaveSettingRequest.php'));
        $settings = file_get_contents(base_path('Modules/Setting/app/Services/Setting/SettingService.php'));

        $this->assertStringContainsString('DeliveryStatus::Investigation, DeliveryStatus::ManualReviewRequired', $listener);
        $this->assertStringContainsString("'admin_delivery_failure_notification_enabled'", $listener);
        $this->assertStringContainsString("'type' => 'delivery_provider_failure'", $listener);
        $this->assertStringContainsString("'severity' => 'error'", $listener);
        $this->assertStringContainsString("'admin_delivery_failure_notification_enabled' => 'sometimes|boolean'", $request);
        $this->assertStringContainsString("'admin_delivery_failure_notification_enabled'] ?? true", $settings);
        $this->assertStringContainsString('implements ShouldHandleEventsAfterCommit', $listener);
        $this->assertStringContainsString("DeliveryStatus::Cancelled => ['setting' => 'admin_delivery_cancelled_notification_enabled'", $listener);
        $this->assertStringContainsString("'staff_delivery_cancelled'", $listener);
        $assignment = file_get_contents(base_path('Modules/Order/app/Jobs/AssignOrderDelivery.php'));
        $this->assertStringContainsString("if (! \$prepared)", $assignment);
        $this->assertStringContainsString('The delivery task was not created.', $assignment);
        $this->assertGreaterThanOrEqual(4, substr_count($assignment, 'notifyManualActionRequired('));
        $this->assertStringContainsString('Delivery Order Not Created', $assignment);
        $this->assertStringContainsString('The restaurant order was received', $assignment);
        $this->assertStringContainsString('Reason:', $assignment);
        $this->assertStringContainsString('Then Use Review And Send', $assignment);
        $this->assertStringNotContainsString('uEngage', $assignment);
        $deliveryAlert = file_get_contents(base_path('Modules/Order/app/Jobs/SendStaffDeliveryActionAlert.php'));
        $this->assertStringContainsString("staff_delivery_not_created", $deliveryAlert);
        $this->assertStringContainsString('$this->templateEvent', $deliveryAlert);
        $this->assertStringNotContainsString("staff_order_received", $deliveryAlert);
        $this->assertStringContainsString("'external_delivery_id' => \$delivery->external_delivery_id", $listener);
        $this->assertStringContainsString("->where('payload->delivery_status', \$event->to->value)", $listener);
    }
}
