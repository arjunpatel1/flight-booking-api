<?php

namespace Tests\Feature\Setting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Enums\SettingSection;
use Modules\Setting\Services\Setting\SettingService;
use Tests\TestCase;

/**
 * Each agency writes its own Terms, Privacy and Refund policies (Settings →
 * Policies); its customer site shows only the ones it has written.
 */
class TenantPoliciesTest extends TestCase
{
    use RefreshDatabase;

    private function agency(string $domain): Tenant
    {
        return Tenant::create([
            'name' => 'Agency ' . Str::random(4), 'slug' => 'agency-' . Str::random(6), 'domain' => $domain, 'is_active' => true,
        ]);
    }

    private function asAgency(Tenant $tenant): void
    {
        app(TenantContext::class)->setId($tenant->id);
    }

    public function test_an_agency_publishes_its_own_policies_and_its_site_shows_only_those(): void
    {
        $agency = $this->agency('policies-one.test');
        $this->asAgency($agency);
        app(SettingService::class)->update(SettingSection::Policies, [
            'policy_terms' => "Terms of use\n\nBookings are confirmed after payment.",
            'policy_privacy' => '',
        ]);

        $settings = app(SettingService::class)->getAppSettings(true);
        $this->assertSame(['terms' => true, 'privacy' => false], $settings['public_policies']);

        $terms = $this->withHeader('X-NexDine-Tenant-Domain', 'policies-one.test')
            ->getJson('/api/v1/app/policies/terms')->assertOk();
        $this->assertStringContainsString('confirmed after payment', $terms->json('body.text'));
        $this->assertNotEmpty($terms->json('body.updated_at'));

        // Not written: no page.
        $this->withHeader('X-NexDine-Tenant-Domain', 'policies-one.test')
            ->getJson('/api/v1/app/policies/privacy')->assertNotFound();
    }

    public function test_one_agencys_policies_never_show_on_another_agencys_site(): void
    {
        $first = $this->agency('policies-first.test');
        $this->agency('policies-second.test');
        $this->asAgency($first);
        app(SettingService::class)->update(SettingSection::Policies, [
            'policy_terms' => 'First agency terms only.',
            'policy_privacy' => '',
        ]);
        app(TenantContext::class)->clear();

        $this->withHeader('X-NexDine-Tenant-Domain', 'policies-second.test')
            ->getJson('/api/v1/app/policies/terms')->assertNotFound();
    }
}
