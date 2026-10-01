<?php

namespace Tests\Unit\Saas;

use Tests\TestCase;

class TenantBoundaryRegressionTest extends TestCase
{
    public function test_tenant_sensitive_analytics_use_tenant_aware_cache_keys(): void
    {
        foreach ([
            'Modules/Report/app/Services/Shift/ShiftAnalyticsService.php',
            'Modules/Report/app/Services/Kitchen/KitchenAnalyticsService.php',
            'Modules/WhatsAppCenter/app/Services/WhatsAppCenterService.php',
        ] as $path) {
            $source = file_get_contents(base_path($path));
            $this->assertIsString($source, $path);
            $this->assertStringContainsString('makeCacheKey(', $source, $path);
        }
    }

    public function test_terminal_heartbeat_rejects_foreign_device_before_returning_payload(): void
    {
        $source = file_get_contents(base_path(
            'Modules/Pos/app/Http/Controllers/Api/V1/PosTerminalDeviceController.php'
        ));

        $this->assertIsString($source);
        $this->assertStringContainsString("'tenant-' . (\$tenantId ?? 'none')", $source);
        $this->assertStringContainsString('deviceBelongsToTenant($existingDevice, $tenantId)', $source);
        $this->assertStringContainsString("->where('branch_id', \$branchId)", $source);
        $this->assertStringContainsString("'assignment_required' => true", $source);
        $this->assertStringContainsString("], 202);", $source);
        $this->assertStringNotContainsString("'assigned_branch_id' => \$existingDevice->branch_id", $source);
    }

    public function test_whatsapp_schedules_are_branch_scoped(): void
    {
        $model = file_get_contents(base_path('Modules/WhatsAppCenter/app/Models/WhatsAppSchedule.php'));
        $controller = file_get_contents(base_path(
            'Modules/WhatsAppCenter/app/Http/Controllers/Api/V1/WhatsAppScheduleController.php'
        ));

        $this->assertStringContainsString('use HasBranch;', $model);
        $this->assertStringContainsString("abort_unless(\$data['branch_id']", $controller);
        $this->assertStringContainsString("where('tenant_id', \$request->user()->tenantId())", $controller);
        $this->assertStringContainsString("'recipients'    => ['required', 'array', 'min:1', 'max:20']", $controller);
        $this->assertStringContainsString("ScheduledReportWhatsAppJob::dispatch(\$schedule->id, (int) \$tenantId", $controller);
    }

    public function test_whatsapp_ordering_resolves_tenant_from_stored_number_and_never_webhook_input(): void
    {
        $controller = file_get_contents(base_path(
            'Modules/WhatsAppCenter/app/Http/Controllers/Api/V1/WhatsAppOrderingController.php'
        ));
        $resolver = file_get_contents(base_path(
            'Modules/WhatsAppCenter/app/Services/WhatsAppChannelContextResolver.php'
        ));

        $this->assertStringContainsString("->where('provider_phone_id', \$providerPhoneId)", $resolver);
        $this->assertStringContainsString("->where('phone_number_id', \$number->id)", $resolver);
        $this->assertStringContainsString("where('tenant_id', \$assignment->tenant_id)", $resolver);
        $this->assertStringContainsString('(int) $profile->tenant_id !== (int) $assignment->tenant_id', $resolver);
        $this->assertStringNotContainsString("request('tenant_id')", $controller);
        $this->assertStringNotContainsString("input('tenant_id')", $controller);
        $provider = file_get_contents(base_path('Modules/WhatsAppCenter/app/Services/Providers/AbstractWhatsAppOrderingProvider.php'));
        $this->assertStringContainsString("hash_hmac('sha256', \$raw, \$secret)", $provider);
    }

    public function test_whatsapp_public_contract_uses_opaque_ids_and_safe_resources(): void
    {
        $engine = file_get_contents(base_path('Modules/WhatsAppCenter/app/Services/WhatsAppOrderingEngine.php'));
        $controller = file_get_contents(base_path('Modules/WhatsAppCenter/app/Http/Controllers/Api/V1/WhatsAppOrderingController.php'));

        $this->assertStringContainsString('{$p->uuid}', $engine);
        $this->assertStringContainsString("->where('uuid', \$id)", $engine);
        $this->assertStringNotContainsString("'order' => ['id'", $controller);
        $this->assertStringContainsString('WhatsAppConnectionResource', $controller);
        $this->assertStringContainsString('WhatsAppConversationResource', $controller);
        $this->assertStringContainsString('WhatsAppOrderSessionResource', $controller);
    }

    public function test_whatsapp_ordering_models_apply_tenant_and_branch_boundaries(): void
    {
        foreach (['WhatsAppProviderProfile', 'WhatsAppTenantAssignment', 'WhatsAppMessage', 'WhatsAppWebhookEvent'] as $model) {
            $source = file_get_contents(base_path("Modules/WhatsAppCenter/app/Models/{$model}.php"));
            $this->assertMatchesRegularExpression('/use [^;]*BelongsToTenant[^;]*;/', $source, $model);
            $this->assertStringContainsString("protected \$table = 'whatsapp_", $source, $model);
        }

        foreach (['WhatsAppConversation', 'WhatsAppOrderSession'] as $model) {
            $source = file_get_contents(base_path("Modules/WhatsAppCenter/app/Models/{$model}.php"));
            $this->assertStringContainsString('BelongsToTenant', $source, $model);
            $this->assertStringContainsString('HasBranch', $source, $model);
            $this->assertStringContainsString("protected \$table = 'whatsapp_", $source, $model);
        }

        $phone = file_get_contents(base_path('Modules/WhatsAppCenter/app/Models/WhatsAppPhoneNumber.php'));
        $this->assertStringContainsString("protected \$table = 'whatsapp_phone_numbers'", $phone);

        $credentialMigration = file_get_contents(base_path('Modules/WhatsAppCenter/database/migrations/2026_08_28_120200_repair_whatsapp_encrypted_credentials_column.php'));
        $this->assertStringContainsString('MODIFY credentials LONGTEXT NOT NULL', $credentialMigration);
    }

    public function test_whatsapp_order_engine_uses_authoritative_branch_cart_and_normal_order_event(): void
    {
        $engine = file_get_contents(base_path('Modules/WhatsAppCenter/app/Services/WhatsAppOrderingEngine.php'));

        $this->assertStringContainsString("where('tenant_id', \$conversation->tenant_id)", $engine);
        $this->assertStringContainsString("where('is_accepting_orders', true)", $engine);
        $this->assertStringContainsString('new ServerCart(new CartDBStorage', $engine);
        $this->assertStringContainsString('$cart->addOrderType($type)', $engine);
        $this->assertStringContainsString('$order->updateOrCreateTaxes($cart->taxes())', $engine);
        $this->assertStringContainsString('$order->syncApplicableOrderTaxes()', $engine);
        $this->assertStringContainsString('$this->branchSchedule->describe($branch)', $engine);
        $this->assertStringContainsString('$this->hasCompleteDeliveryAddress($session->delivery_address)', $engine);
        $this->assertStringContainsString('$branch->delivery_minimum_order', $engine);
        $this->assertStringContainsString('event(new OrderCreated($order))', $engine);
        $this->assertStringNotContainsString("payment_status' => OrderPaymentStatus::Paid", $engine);
    }

    public function test_whatsapp_order_payment_requires_approved_order_and_server_gateway_session(): void
    {
        $controller = file_get_contents(base_path('Modules/WhatsAppCenter/app/Http/Controllers/Api/V1/WhatsAppOrderingController.php'));
        $this->assertStringContainsString("where('tenant_id', \$this->tenantId(\$request))", $controller);
        $this->assertStringContainsString('Approve the WhatsApp order before creating payment.', $controller);
        $this->assertStringContainsString("data_get(\$assignment->capabilities, 'payments', false)", $controller);
        $this->assertStringContainsString('$service->create(', $controller);
    }

    public function test_whatsapp_delivery_address_update_is_tenant_scoped_and_locked_after_order_creation(): void
    {
        $controller = file_get_contents(base_path('Modules/WhatsAppCenter/app/Http/Controllers/Api/V1/WhatsAppOrderingController.php'));
        $this->assertStringContainsString("'address_line1' => ['required'", $controller);
        $this->assertStringContainsString("where('tenant_id', \$this->tenantId(\$request))->where('uuid', \$uuid)", $controller);
        $this->assertStringContainsString("abort_if(\$session->order_id", $controller);
        $this->assertStringContainsString("\$session->update(['delivery_address' => \$data])", $controller);
    }

    public function test_branch_owned_reporting_models_apply_the_tenant_boundary(): void
    {
        foreach ([
            'FactExpenseDaily.php',
            'FactKitchenDaily.php',
            'FactKotDaily.php',
            'FactShiftDaily.php',
            'WaiterDailyCollection.php',
            'MonthlySalesReport.php',
            'ReportSchedule.php',
            'ReportExportAudit.php',
            'ReportJob.php',
            'ReportExport.php',
            'DashboardSnapshot.php',
            'SavedReportFilter.php',
        ] as $model) {
            $source = file_get_contents(base_path("Modules/Report/app/Models/{$model}"));

            $this->assertIsString($source, $model);
            $this->assertStringContainsString('Modules\\Branch\\Traits\\HasBranch', $source, $model);
            $this->assertMatchesRegularExpression('/use [^;]*HasBranch[^;]*;/', $source, $model);
        }
    }

    public function test_raw_gst_queries_resolve_only_actor_tenant_branches(): void
    {
        $source = file_get_contents(base_path(
            'Modules/Report/app/Services/Gst/GSTCalculationService.php'
        ));

        $this->assertStringContainsString('allowedBranchIds($branchId)', $source);
        $this->assertStringContainsString("->where('tenant_id', \$tenantId)", $source);
        $this->assertStringContainsString("->whereIn('orders.branch_id', \$ids)", $source);
        $this->assertStringContainsString('return collect();', $source);
    }
}
