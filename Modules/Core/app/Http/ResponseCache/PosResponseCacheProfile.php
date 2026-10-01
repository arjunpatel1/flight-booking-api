<?php

namespace Modules\Core\Http\ResponseCache;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\ResponseCache\CacheProfiles\BaseCacheProfile;
use Spatie\ResponseCache\Enums\HttpMethod;
use Symfony\Component\HttpFoundation\Response;

class PosResponseCacheProfile extends BaseCacheProfile
{
    public function shouldCacheRequest(Request $request): bool
    {
        if ($request->ajax()) {
            return false;
        }

        if (! $request->isMethod(HttpMethod::Get->value) && ! $request->isMethod(HttpMethod::Head->value)) {
            return false;
        }

        if ($request->bearerToken() || $request->user()) {
            return false;
        }

        return $this->pathMatches(
            $request,
            (array) config('responsecache.pos.cacheable_paths', [])
        );
    }

    public function shouldCacheResponse(Response $response): bool
    {
        if (! $response->isSuccessful()) {
            return false;
        }

        $contentType = $response->headers->get('Content-Type', '');

        return Str::contains($contentType, ['/json', '+json']);
    }

    public function cacheLifetimeInSeconds(Request $request): int
    {
        return (int) config(
            'responsecache.pos.lifetime_in_seconds',
            parent::cacheLifetimeInSeconds($request)
        );
    }

    public function useCacheNameSuffix(Request $request): string
    {
        $suffixParts = array_filter([
            $request->headers->get('X-Tenant-ID'),
            $request->headers->get('X-Branch-ID'),
            $request->query('branch_id'),
        ]);

        return implode('|', $suffixParts);
    }

    private function pathMatches(Request $request, array $paths): bool
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && $request->is($path)) {
                return true;
            }
        }

        return false;
    }
}
