<?php

namespace Modules\Saas\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * The standard tenant-aware cache helper (Phase 2, Module 3).
 *
 * A thin wrapper over the cache that namespaces every key by the current tenant
 * via TenantResourceNaming, so a caller cannot forget the prefix. New code
 * should use this instead of `Cache::` directly.
 *
 * It intentionally does NOT rip out the 73 existing raw `Cache::` sites — those
 * live in business modules this phase must not modify, and most already route
 * through the tenant-prefixing `makeCacheKey` helper. This is the going-forward
 * standard and the target those sites migrate to, not a big-bang rewrite.
 *
 * Backward compatible: keys produced here are namespaced, so they never collide
 * with, nor overwrite, keys written by existing code.
 */
class TenantAwareCache
{
    public function __construct(private readonly TenantResourceNaming $naming)
    {
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        return Cache::put($this->naming->cacheKey($key), $value, $ttl);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::get($this->naming->cacheKey($key), $default);
    }

    public function remember(string $key, mixed $ttl, Closure $callback): mixed
    {
        return Cache::remember($this->naming->cacheKey($key), $ttl, $callback);
    }

    public function rememberForever(string $key, Closure $callback): mixed
    {
        return Cache::rememberForever($this->naming->cacheKey($key), $callback);
    }

    public function forget(string $key): bool
    {
        return Cache::forget($this->naming->cacheKey($key));
    }

    public function has(string $key): bool
    {
        return Cache::has($this->naming->cacheKey($key));
    }

    /** Expose the resolved key, for callers that must pass it elsewhere. */
    public function key(string $key): string
    {
        return $this->naming->cacheKey($key);
    }
}
