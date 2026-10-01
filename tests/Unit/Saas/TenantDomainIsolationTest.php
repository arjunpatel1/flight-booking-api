<?php

namespace Tests\Unit\Saas;

use Illuminate\Http\Request;
use Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Support\TenantContext;
use Modules\User\Models\User;
use Modules\User\Services\Auth\TenantHandoffService;
use Tests\TestCase;

class TenantDomainIsolationTest extends TestCase
{
    public function test_tenant_identity_is_rejected_when_browser_host_resolves_to_platform(): void
    {
        app(TenantContext::class)->clear();
        $user = new User;
        $user->forceFill(['id' => 55, 'tenant_id' => 20, 'branch_id' => 26]);
        $request = Request::create('/api/v1/dashboard', 'GET');
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('X-NexDine-Tenant-Domain', 'nexdine.myteknoland.in');
        $request->setUserResolver(fn () => $user);

        $response = app(EnsureAuthenticatedTenant::class)->handle(
            $request,
            fn () => response()->json(['unexpected' => true]),
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('TENANT_DOMAIN_REQUIRED', data_get(json_decode($response->getContent(), true), 'errors.code'));
    }

    public function test_support_handoff_uses_registered_tenant_domain_only(): void
    {
        $tenant = new Tenant;
        $tenant->forceFill([
            'domain' => 'manu-family-restaurant.nexdine.myteknoland.in',
            'settings' => ['frontend_url' => 'https://nexdine.myteknoland.in'],
        ]);
        $service = app(TenantHandoffService::class);
        $method = new \ReflectionMethod($service, 'handoffUrl');

        $url = $method->invoke($service, $tenant, 'one-time-token', 'https://attacker.example');

        $this->assertSame(
            'https://manu-family-restaurant.nexdine.myteknoland.in/auth/tenant-handoff?token=one-time-token',
            $url,
        );
    }
}
