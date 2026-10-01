<?php

namespace Tests\Unit\Order;

use PHPUnit\Framework\TestCase;

class OrderCreatedAdminNotificationContractTest extends TestCase
{
    public function test_order_created_registers_a_tenant_scoped_admin_notification(): void
    {
        $provider = file_get_contents(__DIR__.'/../../../Modules/Order/app/Providers/EventServiceProvider.php');
        $listener = file_get_contents(__DIR__.'/../../../Modules/Order/app/Listeners/NotifyTenantAdminsOfNewOrder.php');

        $this->assertStringContainsString('NotifyTenantAdminsOfNewOrder::class', $provider);
        $this->assertStringContainsString('implements ShouldHandleEventsAfterCommit', $listener);
        // Recipient resolution (tenant, active, login-enabled, staff guard) is
        // shared and covered behaviourally by CustomerWebOrderAdminNotificationTest.
        $this->assertStringContainsString("TenantStaffRecipients::withAnyPermission((int) \$tenantId, ['admin.orders.index', 'admin.orders.show'])", $listener);
        $this->assertStringNotContainsString('->can(', $listener);
        $this->assertStringContainsString('action_url', $listener);
        $this->assertStringContainsString('catch (\\Throwable $exception)', $listener);
        $this->assertStringContainsString('return $value === null || filter_var($value, FILTER_VALIDATE_BOOL);', $listener);
    }

    public function test_order_created_does_not_invoke_the_status_only_delivery_listener(): void
    {
        $provider = file_get_contents(__DIR__.'/../../../Modules/Order/app/Providers/EventServiceProvider.php');
        preg_match('/OrderCreated::class => \[(.*?)\],/s', $provider, $match);

        $this->assertNotEmpty($match[1] ?? null);
        $this->assertStringNotContainsString('StartDeliveryAfterKitchenAcceptance::class', $match[1]);
        $this->assertStringContainsString('NotifyTenantAdminsOfNewOrder::class', $match[1]);
        $this->assertStringContainsString('UpdateCustomerExperience::class', $match[1]);
    }

    public function test_customer_whatsapp_listener_establishes_tenant_settings_for_webhook_events(): void
    {
        $listener = file_get_contents(__DIR__.'/../../../Modules/Order/app/Listeners/SendOrderWhatsAppNotification.php');

        $this->assertStringContainsString('private readonly TenantContext $tenantContext', $listener);
        $this->assertStringContainsString('$this->tenantContext->setId($tenantId)', $listener);
        $this->assertStringContainsString('$this->settings->refreshSettingBinding()', $listener);
        $this->assertStringContainsString('$this->tenantContext->setId($previousTenantId)', $listener);
    }
}
