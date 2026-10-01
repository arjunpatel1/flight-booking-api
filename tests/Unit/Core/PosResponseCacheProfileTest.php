<?php

namespace Tests\Unit\Core;

use Illuminate\Http\Request;
use Modules\Core\Http\ResponseCache\PosResponseCacheProfile;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class PosResponseCacheProfileTest extends TestCase
{
    public function test_it_does_not_cache_when_no_paths_are_configured(): void
    {
        config()->set('responsecache.pos.cacheable_paths', []);

        $profile = new PosResponseCacheProfile();
        $request = Request::create('/api/v1/public/menu', 'GET');

        $this->assertFalse($profile->shouldCacheRequest($request));
    }

    public function test_it_allows_only_configured_unauthenticated_get_paths(): void
    {
        config()->set('responsecache.pos.cacheable_paths', ['api/v1/public/menu*']);

        $profile = new PosResponseCacheProfile();

        $this->assertTrue($profile->shouldCacheRequest(
            Request::create('/api/v1/public/menu/abc', 'GET')
        ));

        $this->assertFalse($profile->shouldCacheRequest(
            Request::create('/api/v1/public/menu/abc', 'POST')
        ));
    }

    public function test_it_rejects_bearer_authenticated_requests(): void
    {
        config()->set('responsecache.pos.cacheable_paths', ['api/v1/pos/*']);

        $profile = new PosResponseCacheProfile();
        $request = Request::create('/api/v1/pos/menu', 'GET');
        $request->headers->set('Authorization', 'Bearer token');

        $this->assertFalse($profile->shouldCacheRequest($request));
    }

    public function test_it_caches_successful_json_responses_only(): void
    {
        $profile = new PosResponseCacheProfile();

        $jsonResponse = new Response('{}', 200, ['Content-Type' => 'application/json']);
        $htmlResponse = new Response('<html></html>', 200, ['Content-Type' => 'text/html']);
        $errorResponse = new Response('{}', 500, ['Content-Type' => 'application/json']);

        $this->assertTrue($profile->shouldCacheResponse($jsonResponse));
        $this->assertFalse($profile->shouldCacheResponse($htmlResponse));
        $this->assertFalse($profile->shouldCacheResponse($errorResponse));
    }

    public function test_cache_suffix_includes_tenant_and_branch_context(): void
    {
        $profile = new PosResponseCacheProfile();
        $request = Request::create('/api/v1/public/menu?branch_id=7', 'GET');
        $request->headers->set('X-Tenant-ID', 'tenant-1');
        $request->headers->set('X-Branch-ID', '3');

        $this->assertSame('tenant-1|3|7', $profile->useCacheNameSuffix($request));
    }
}
