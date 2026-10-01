<?php

namespace Tests\Feature\Setting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Media\Enum\MediaType;
use Modules\Media\Models\Media;
use Modules\Saas\Models\Tenant;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

class LogoTenantIsolationTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    public function test_tenant_cannot_select_another_tenants_logo(): void
    {
        $tenantA = $this->tenant('logo-a');
        $tenantB = $this->tenant('logo-b');
        $branchA = $this->makeBranch(['tenant_id' => $tenantA->id]);
        $actorA = $this->actingAsUserWithPermissions(['admin.settings.edit']);
        $actorA->forceFill(['tenant_id' => $tenantA->id, 'branch_id' => $branchA->id])->save();
        $actorB = $this->actingAsUserWithPermissions([]);
        $actorB->forceFill(['tenant_id' => $tenantB->id])->save();
        $foreignLogo = Media::query()->forceCreate([
            'type' => MediaType::File->value,
            'name' => 'tenant-b-logo.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size' => 10,
            'path' => 'tenants/'.$tenantB->id.'/media/tenant-b-logo.png',
            'disk' => 'public',
            'created_by' => $actorB->id,
        ]);

        $this->actingAs($actorA->refresh(), 'api')
            ->putJson('/api/v1/settings/logo/update', [
                'logo' => $foreignLogo->id,
                'favicon' => $foreignLogo->id,
                'loader_logo' => $foreignLogo->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['logo', 'favicon', 'loader_logo']);
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->withoutGlobalScopes()->create([
            'name' => $slug,
            'slug' => $slug,
            'domain' => $slug.'.example.test',
            'is_active' => true,
        ]);
    }
}
