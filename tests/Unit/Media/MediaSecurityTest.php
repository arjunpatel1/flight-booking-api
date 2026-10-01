<?php

namespace Tests\Unit\Media;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Modules\Media\Enum\MediaType;
use Modules\Media\Models\Media;
use Modules\Saas\Traits\BelongsToTenant;
use Tests\TestCase;

class MediaSecurityTest extends TestCase
{
    public function test_media_download_requires_authentication_and_media_read_permission(): void
    {
        $route = collect(RouteFacade::getRoutes())->first(fn (Route $route) =>
            $route->uri() === 'api/v1/media/{id}/download'
            && in_array('GET', $route->methods(), true)
        );

        $this->assertNotNull($route);
        $this->assertContains('can:admin.media.index', $route->gatherMiddleware());
        $this->assertContains(Authenticate::class, app('router')->gatherRouteMiddleware($route));
    }

    public function test_media_records_have_a_tenant_boundary_and_tenant_scoped_tree(): void
    {
        $this->assertContains(BelongsToTenant::class, class_uses_recursive(Media::class));
        $this->assertSame(['tenant_id'], (new Media())->getScopeAttributes());

        $migration = file_get_contents(base_path(
            'Modules/Media/database/migrations/2026_08_24_000001_add_tenant_ownership_to_media.php'
        ));

        $this->assertIsString($migration);
        $this->assertStringContainsString("foreignId('tenant_id')", $migration);
    }

    public function test_non_image_media_never_exposes_a_direct_storage_url(): void
    {
        $media = new Media([
            'type' => MediaType::File,
            'mime_type' => 'application/pdf',
            'disk' => 'public',
            'path' => 'media/sensitive.pdf',
        ]);
        $media->forceFill(['id' => 123]);
        $media->exists = true;

        $this->assertNull($media->url);
        $this->assertNotNull($media->download_url);
    }

    public function test_upload_service_places_non_images_on_private_storage(): void
    {
        $source = file_get_contents(base_path('Modules/Media/app/Services/Media/MediaService.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("config('media.private_disk', 'local')", $source);
        $this->assertStringContainsString("'tenant_id' => \$tenantId", $source);
    }

    public function test_unassigned_media_cache_lookup_is_limited_to_global_assets(): void
    {
        $source = file_get_contents(base_path('Modules/Media/app/Models/Media.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString(
            "! \$tenantId && ! \$isPlatformAdministrator",
            $source
        );
        $this->assertStringContainsString("whereNull('tenant_id')", $source);
    }
}
