<?php

namespace Tests\Unit\Saas;

use Modules\Saas\Services\Provisioning\SaasServerAutomationService;
use Tests\TestCase;

class SaasEnterpriseConfigTest extends TestCase
{
    public function test_enterprise_features_and_queues_are_centralized(): void
    {
        $features = config('saas.enterprise_features');
        $queues = config('saas.queues');

        $this->assertContains('pos', $features);
        $this->assertContains('waiter_app', $features);
        $this->assertContains('printer', $features);
        $this->assertContains('reservations', $features);
        $this->assertArrayHasKey('provisioning', $queues);
        $this->assertArrayHasKey('assets', $queues);
        $this->assertArrayHasKey('delivery', $queues);
        $this->assertArrayHasKey('monitoring', $queues);
    }

    public function test_default_signup_trial_matches_the_three_month_billing_policy(): void
    {
        $this->assertSame(90, (int) config('saas.billing.trial_days'));
        $this->assertSame(90, (int) config('saas.self_service.trial_days'));
        $this->assertSame(30, (int) config('saas.support.session_minutes'));
    }

    public function test_server_automation_supports_nginx_health_and_dry_run(): void
    {
        config([
            'saas.server_automation.enabled' => false,
            'saas.server_automation.allow_apply' => false,
            'saas.server_automation.web_server' => 'nginx',
        ]);

        $service = app(SaasServerAutomationService::class);
        $health = $service->health();

        $this->assertSame('nginx', $health['automation']['default_web_server']);
        $this->assertTrue($health['web_servers']['nginx']['exists']);

        $result = $service->configureWebServerSsl([
            'web_server' => 'nginx',
            'api_domain' => 'api.nexdine.test',
            'root_domain' => 'nexdine.test',
            'tenant_domains' => 'chirag.nexdine.test,happy.nexdine.test',
            'frontend_root' => base_path('../dist'),
            'api_root' => public_path(),
            'email' => 'admin@nexdine.test',
            'apply' => false,
            'install_packages' => false,
        ]);

        $this->assertFalse($result['applied']);
        $this->assertTrue($result['successful']);
        $this->assertSame('nginx', $result['web_server']);
        $this->assertStringContainsString('certbot --nginx', $result['output']);
    }

    public function test_server_automation_health_explains_why_ssl_apply_is_disabled(): void
    {
        config([
            'saas.server_automation.enabled' => false,
            'saas.server_automation.allow_apply' => false,
            'saas.server_automation.frontend_root' => '',
            'saas.server_automation.ssl_email' => '',
        ]);

        $automation = app(SaasServerAutomationService::class)->health()['automation'];

        $this->assertFalse($automation['apply_ready']);
        $this->assertContains('Server automation is disabled.', $automation['apply_blockers']);
        $this->assertContains('Apply mode is disabled.', $automation['apply_blockers']);
        $this->assertContains('Frontend build path is not configured.', $automation['apply_blockers']);
        $this->assertContains('Certificate notices email is not configured.', $automation['apply_blockers']);
    }

    public function test_automatic_tenant_ssl_uses_an_isolated_exact_host_vhost(): void
    {
        config([
            'saas.server_automation.enabled' => false,
            'saas.server_automation.allow_apply' => false,
        ]);

        $result = app(SaasServerAutomationService::class)->configureTenantSsl([
            'mode' => 'tenant_ssl',
            'tenant_domain' => 'redison-blu.nexdine.test',
            'frontend_root' => public_path(),
            'email' => 'admin@nexdine.test',
            'apply' => false,
        ]);

        $this->assertFalse($result['applied']);
        $this->assertTrue($result['successful']);
        $this->assertSame('tenant_ssl', $result['mode']);
        $this->assertSame('redison-blu.nexdine.test', $result['tenant_domain']);
        $this->assertStringContainsString('validate root-owned allowlist', $result['output']);
        $this->assertStringContainsString('issue/renew certificate and atomically install', $result['output']);
        $this->assertStringContainsString('server_name redison-blu.nexdine.test', $result['output']);
        $this->assertStringContainsString('Strict-Transport-Security', $result['output']);
        $this->assertStringContainsString('Content-Security-Policy "', $result['output']);
        $this->assertStringNotContainsString('Content-Security-Policy-Report-Only', $result['output']);
        $this->assertGreaterThanOrEqual(3, substr_count($result['output'], 'X-Content-Type-Options'));
        $this->assertStringNotContainsString('api.nexdine.test', $result['output']);
    }

    public function test_tenant_ssl_apply_is_blocked_until_server_automation_is_authorized(): void
    {
        config([
            'saas.server_automation.enabled' => false,
            'saas.server_automation.allow_apply' => false,
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('Server automation is disabled');

        app(SaasServerAutomationService::class)->configureTenantSsl([
            'tenant_domain' => 'new-restaurant.nexdine.test',
            'frontend_root' => base_path('../dist'),
            'email' => 'admin@nexdine.test',
            'apply' => true,
        ]);
    }

    public function test_tenant_ssl_rejects_an_invalid_hostname_before_running_a_process(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('valid tenant domain');

        app(SaasServerAutomationService::class)->configureTenantSsl([
            'tenant_domain' => 'https://foreign.example.test/path',
            'frontend_root' => base_path('../dist'),
            'email' => 'admin@nexdine.test',
            'apply' => false,
        ]);
    }
}
