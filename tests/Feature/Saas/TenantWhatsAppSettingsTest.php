<?php
namespace Tests\Feature\Saas;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Modules\Saas\Http\Controllers\Api\V1\TenantWhatsAppSettingsController;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Models\Setting;
use Modules\Setting\Enums\SettingSection;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use Modules\Setting\Services\Setting\SettingService;
use Tests\TestCase;

class TenantWhatsAppSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_account_can_be_saved_without_secret_but_cannot_be_enabled(): void
    {
        Queue::fake(); Http::preventStrayRequests();
        $tenant = Tenant::query()->withoutGlobalScopes()->create(['name' => 'Disabled connection', 'slug' => 'disabled-connection', 'is_active' => true]);
        $controller = app(TenantWhatsAppSettingsController::class);
        $controller->update(Request::create('/', 'PUT', ['account_id' => 'new-account', 'enabled' => false]), $tenant->id);
        $this->assertStringContainsString('new-account', $controller->show($tenant->id)->getContent());
        $this->expectException(ValidationException::class);
        $controller->update(Request::create('/', 'PUT', ['account_id' => 'new-account', 'enabled' => true]), $tenant->id);
    }

    public function test_connection_is_encrypted_tenant_scoped_and_secret_is_not_returned(): void
    {
        Queue::fake(); Http::preventStrayRequests();
        $tenant = Tenant::query()->withoutGlobalScopes()->create(['name' => 'Connection test', 'slug' => 'connection-test', 'is_active' => true]);
        $controller = app(TenantWhatsAppSettingsController::class);
        $request = Request::create('/', 'PUT', ['account_id' => '6aabc8ecd982a2bddbd1ccb6', 'auth_key' => 'test-secret-key', 'enabled' => false, 'test_recipient' => '917389175732']);
        $controller->update($request, $tenant->id);
        $secret = Setting::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('key', 'whatsapp_nexmsg_auth_key')->firstOrFail();
        $this->assertTrue($secret->is_encryptable);
        $this->assertSame('test-secret-key', $secret->payload);
        $this->assertStringNotContainsString('test-secret-key', $secret->getRawOriginal('payload'));
        $this->assertStringNotContainsString('test-secret-key', $controller->show($tenant->id)->getContent());
        $this->assertNull(app(TenantContext::class)->id());
        $request = Request::create('/', 'PUT', ['account_id' => '6aabc8ecd982a2bddbd1ccb6', 'auth_key' => '', 'enabled' => true]);
        $controller->update($request, $tenant->id);
        $this->assertSame('test-secret-key', $secret->fresh()->payload);
        $this->expectException(ValidationException::class);
        $controller->update(Request::create('/', 'PUT', ['account_id' => 'different-account', 'enabled' => true]), $tenant->id);
    }

    public function test_restaurant_settings_cannot_enable_nexmsg_without_auth_key(): void
    {
        Queue::fake(); Http::preventStrayRequests();
        $tenant = Tenant::query()->withoutGlobalScopes()->create(['name' => 'Settings guard', 'slug' => 'settings-guard', 'is_active' => true]);
        app(TenantContext::class)->setId($tenant->id);
        app(SettingServiceInterface::class)->refreshSettingBinding();

        $payload = [
            'whatsapp_enabled' => true,
            'whatsapp_provider' => 'nexmsg',
            'whatsapp_delivery_alerts_enabled' => false,
            'whatsapp_marketing_campaigns_enabled' => false,
            'crm_inactive_customer_days' => 30,
            'crm_recent_customer_days' => 7,
            'crm_high_value_customer_min_spend' => 500,
            'crm_inactive_customer_automation_enabled' => false,
            'crm_inactive_customer_automation_time' => '10:00',
            'crm_birthday_offer_automation_enabled' => false,
            'crm_birthday_offer_automation_time' => '09:00',
            'crm_anniversary_offer_automation_enabled' => false,
            'crm_anniversary_offer_automation_time' => '09:00',
            'whatsapp_templates' => [],
            'whatsapp_msg91_marketing_reuse_utility' => true,
            'whatsapp_meta_graph_version' => '23.0',
            'encryptable' => ['whatsapp_nexmsg_account_id' => '6aabc8ecd982a2bddbd1ccb6'],
        ];

        $this->expectException(ValidationException::class);
        app(SettingService::class)->update(SettingSection::WhatsApp, $payload);
    }

    public function test_restaurant_cannot_reuse_saved_key_for_a_changed_account_even_when_disabled(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $tenant = Tenant::query()->withoutGlobalScopes()->create(['name' => 'Account rotation', 'slug' => 'account-rotation', 'is_active' => true]);
        app(TenantWhatsAppSettingsController::class)->update(Request::create('/', 'PUT', [
            'account_id' => 'original-account', 'auth_key' => 'test-only-key', 'enabled' => false,
        ]), $tenant->id);
        app(TenantContext::class)->setId($tenant->id);
        $service = app(SettingService::class);
        $service->refreshSettingBinding();
        try {
            $service->update(SettingSection::WhatsApp, [
                'whatsapp_enabled' => false, 'whatsapp_provider' => 'nexmsg',
                'encryptable' => ['whatsapp_nexmsg_account_id' => 'different-account', 'whatsapp_nexmsg_auth_key' => ''],
            ]);
            $this->fail('Changing accounts without a replacement key must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('encryptable.whatsapp_nexmsg_auth_key', $exception->errors());
            $this->assertSame('original-account', setting('whatsapp_nexmsg_account_id'));
            $this->assertSame('test-only-key', setting('whatsapp_nexmsg_auth_key'));
        } finally {
            app(TenantContext::class)->setId(null);
        }
    }
}
