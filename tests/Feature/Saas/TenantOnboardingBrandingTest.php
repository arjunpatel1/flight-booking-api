<?php

namespace Tests\Feature\Saas;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Models\Media;
use Modules\Saas\Services\Provisioning\SaasProvisioningService;
use Tests\TestCase;

/**
 * Branding entered by the platform admin when creating an agency reaches the
 * settings the agency's admin pages and customer site read:
 * the uploaded logo becomes the tenant's `logo` (the one Settings → Logo
 * manages), and the chosen colours become the appearance theme colours.
 */
class TenantOnboardingBrandingTest extends TestCase
{
    use RefreshDatabase;

    /** A real 2x2 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP4z8DwnwEIGBgY/oNYAAAzZgX/PNzrdAAAAABJRU5ErkJggg==';

    private function tenantSetting(int $tenantId, string $key): mixed
    {
        $payload = DB::table('settings')->where('setting_scope', "tenant:{$tenantId}")->where('key', $key)->value('payload');

        return is_string($payload) ? unserialize($payload, ['allowed_classes' => false]) : null;
    }

    private function provision(array $extra): int
    {
        $result = app(SaasProvisioningService::class)->provision(array_merge([
            'name' => 'Sky Agency',
            'slug' => 'sky-agency-' . uniqid(),
            'email' => 'owner+' . uniqid() . '@example.test',
            'phone' => '9000000001',
            'admin_name' => 'Sky Owner',
            'password' => 'Secret#12345',
            'plan' => 'starter',
        ], $extra));

        return (int) $result['tenant']->id;
    }

    public function test_uploaded_logo_and_colours_become_the_agency_branding(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('public');

        $tenantId = $this->provision([
            'logo_data' => 'data:image/png;base64,' . self::PNG,
            'primary_color' => '#1a7f37',
            'secondary_color' => '#112233',
        ]);

        $mediaId = $this->tenantSetting($tenantId, 'logo');
        $this->assertIsInt($mediaId, 'The uploaded logo is saved as the tenant logo setting.');
        $media = Media::query()->withoutGlobalScopes()->find($mediaId);
        $this->assertNotNull($media);
        $this->assertSame($tenantId, (int) $media->tenant_id);
        $this->assertSame('image/png', $media->mime_type);
        $this->assertStringStartsWith("tenants/{$tenantId}/media/", $media->path);
        Storage::disk('public')->assertExists($media->path);

        $this->assertSame('#1A7F37', $this->tenantSetting($tenantId, 'appearance_primary_color'));
        $this->assertSame('#112233', $this->tenantSetting($tenantId, 'appearance_secondary_color'));
        $this->assertSame('Sky Agency', ($this->tenantSetting($tenantId, 'app_name') ?? [])['en'] ?? null);
    }

    public function test_the_agency_site_settings_serve_the_onboarding_logo_and_colours(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('public');

        $tenantId = $this->provision([
            'logo_data' => 'data:image/png;base64,' . self::PNG,
            'primary_color' => '#1a7f37',
            'secondary_color' => '#112233',
        ]);
        $media = Media::query()->withoutGlobalScopes()->find($this->tenantSetting($tenantId, 'logo'));

        // What the agency's admin pages and customer site load (/v1/app/settings).
        app(\Modules\Saas\Support\TenantContext::class)->setId($tenantId);
        $settings = app(\Modules\Setting\Services\Setting\SettingServiceInterface::class)->getAppSettings(true);

        $this->assertStringContainsString($media->path, (string) $settings['logo']);
        $this->assertSame('#1A7F37', $settings['appearance']['theme']['colors']['primary']);
        $this->assertSame('#112233', $settings['appearance']['theme']['colors']['secondary']);
        $this->assertSame('Sky Agency', $settings['public_brand']['name']);
        $this->assertSame('tenant', $settings['public_brand']['scope']);
    }

    public function test_without_a_logo_file_no_logo_is_set_and_creation_still_succeeds(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('public');

        $tenantId = $this->provision([]);

        $this->assertNull($this->tenantSetting($tenantId, 'logo'));
    }

    public function test_a_file_that_is_not_an_image_is_skipped_without_blocking_creation(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('public');

        // Claims to be a PNG but is plain text: the media checks reject it.
        $tenantId = $this->provision(['logo_data' => 'data:image/png;base64,' . base64_encode('not really an image')]);

        $this->assertNull($this->tenantSetting($tenantId, 'logo'));
    }
}
