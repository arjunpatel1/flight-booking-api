<?php

namespace Tests\Unit\Security;

use Tests\TestCase;

class TenantNginxProvisioningTest extends TestCase
{
    public function test_tenant_vhost_keeps_the_stable_atomic_release_path(): void
    {
        $script = file_get_contents(base_path('Modules/Saas/deploy/nginx-tenant-ssl.sh'));

        $this->assertIsString($script);
        $this->assertStringContainsString('requested_root="$(realpath -e -- "$FRONTEND_ROOT")"', $script);
        $this->assertStringContainsString('approved_root="$(realpath -e -- "$ALLOWED_FRONTEND_ROOT")"', $script);
        $this->assertStringContainsString('FRONTEND_ROOT="${ALLOWED_FRONTEND_ROOT%/}"', $script);
        $this->assertStringNotContainsString('FRONTEND_ROOT="$approved_root"', $script);
        $this->assertStringContainsString('try_files \$uri \$uri/ /index.html;', $script);
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' https://checkout.razorpay.com", $script);
        $this->assertStringContainsString('https://api.razorpay.com', $script);
    }
}
