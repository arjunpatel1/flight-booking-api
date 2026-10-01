<?php
namespace Tests\Unit\Notification;

use Modules\Notification\Services\Channels\WhatsAppChannel;
use Modules\Notification\Services\WhatsApp\WhatsAppProviderFactory;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use Tests\TestCase;

class WhatsAppDisabledChannelTest extends TestCase
{
    public function test_delayed_job_respects_disabled_master_switch_without_provider_access(): void
    {
        $settings = $this->createMock(SettingServiceInterface::class);
        $settings->expects($this->exactly(2))->method('refreshSettingBinding');
        app()->instance(SettingServiceInterface::class, $settings);
        app()->instance('setting', new class {
            public function get(string $key, mixed $default = null): mixed { return $key === 'whatsapp_enabled' ? false : $default; }
        });
        $factory = $this->createMock(WhatsAppProviderFactory::class);
        $factory->expects($this->never())->method('make');
        (new \Modules\Notification\Jobs\SendWhatsAppMessageJob('919876543210', 'receipt', [], ['tenant_id' => 20]))->handle($factory);
        $this->assertNull(app(TenantContext::class)->id());
    }

    public function test_disabled_tenant_does_not_call_provider_and_restores_context(): void
    {
        $context = app(TenantContext::class);
        $context->setId(42);
        $settings = $this->createMock(SettingServiceInterface::class);
        $settings->expects($this->exactly(2))->method('refreshSettingBinding');
        app()->instance(SettingServiceInterface::class, $settings);
        app()->instance('setting', new class {
            public function get(string $key, mixed $default = null): mixed { return $key === 'whatsapp_enabled' ? false : $default; }
        });
        $factory = $this->createMock(WhatsAppProviderFactory::class);
        $factory->expects($this->never())->method('make');
        try {
            (new WhatsAppChannel($factory))->send('919876543210', ['tenant_id' => 20]);
            $this->fail('Disabled messaging must fail before sending.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('disabled', $exception->getMessage());
        }
        $this->assertSame(42, $context->id());
    }
}
