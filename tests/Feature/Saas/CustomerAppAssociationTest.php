<?php

namespace Tests\Feature\Saas;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Saas\Models\CustomerAppRegistration;
use Modules\Saas\Models\Tenant;
use Tests\TestCase;

class CustomerAppAssociationTest extends TestCase
{
    use RefreshDatabase;

    private const ANDROID = '/api/v1/saas/customer-app/associations/android';

    private const APPLE = '/api/v1/saas/customer-app/associations/apple';

    public function test_android_association_uses_only_active_exact_verified_registrations(): void
    {
        $tenant = $this->tenant('links');
        $this->registration($tenant, 'in.myteknoland.nexdine.links', 'android', 'active', str_repeat('ab', 32));
        $this->registration($this->tenant('revoked'), 'in.myteknoland.nexdine.revoked', 'android', 'revoked', str_repeat('cd', 32));

        $response = $this->getJson(self::ANDROID)->assertOk();

        $response->assertHeader('content-type', 'application/json');
        $this->assertSame('in.myteknoland.nexdine.links', $response->json('0.target.package_name'));
        $this->assertSame(implode(':', array_fill(0, 32, 'AB')), $response->json('0.target.sha256_cert_fingerprints.0'));
        $this->assertCount(1, $response->json());
    }

    public function test_associations_fail_closed_without_verified_release_identity(): void
    {
        $tenant = $this->tenant('unconfigured');
        $this->registration($tenant, 'in.myteknoland.nexdine.unconfigured', 'android', 'active', null);
        config(['saas.customer_app_links.apple_team_id' => null]);

        $this->getJson(self::ANDROID)->assertStatus(503);
        $this->getJson(self::APPLE)->assertStatus(503);
    }

    public function test_apple_association_uses_configured_team_and_active_bundle_only(): void
    {
        $tenant = $this->tenant('apple');
        $this->registration($tenant, 'in.myteknoland.nexdine.apple', 'ios', 'active', null);
        $this->registration($this->tenant('old'), 'in.myteknoland.nexdine.old', 'ios', 'revoked', null);
        config(['saas.customer_app_links.apple_team_id' => 'A1B2C3D4E5']);

        $response = $this->getJson(self::APPLE)->assertOk();

        $this->assertSame('A1B2C3D4E5.in.myteknoland.nexdine.apple', $response->json('applinks.details.0.appID'));
        $this->assertSame('/customer-app/group', $response->json('applinks.details.0.components.0./'));
        $this->assertCount(1, $response->json('applinks.details'));
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->withoutGlobalScopes()->create([
            'name' => ucfirst($slug), 'slug' => $slug,
            'domain' => $slug.'.example.test', 'is_active' => true,
        ]);
    }

    private function registration(Tenant $tenant, string $packageId, string $platform, string $status, ?string $fingerprint): void
    {
        CustomerAppRegistration::query()->create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $tenant->id,
            'package_id' => $packageId, 'display_name' => $tenant->name,
            'platform' => $platform, 'status' => $status, 'branding_revision' => 1,
            'signing_certificate_fingerprint' => $fingerprint,
        ]);
    }
}
