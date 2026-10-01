<?php

namespace Modules\Core\Traits;

use Illuminate\Support\Facades\Cache;

trait Cacheable
{
    /**
     * Cache key prefix for this model/service.
     *
     * @var string
     */
    protected string $cachePrefix = 'cache';

    /**
     * Cache duration in seconds (default: 5 minutes).
     *
     * @var int
     */
    protected int $cacheDuration = 300;

    /**
     * Remember a value in cache.
     *
     * @param string $key
     * @param callable $callback
     * @param int|null $duration
     * @return mixed
     */
    protected function remember(string $key, callable $callback, ?int $duration = null): mixed
    {
        $cacheKey = $this->getCacheKey($key);
        $cacheDuration = $duration ?? $this->cacheDuration;

        return Cache::remember($cacheKey, $cacheDuration, $callback);
    }

    /**
     * Remember a value in cache forever.
     *
     * @param string $key
     * @param callable $callback
     * @return mixed
     */
    protected function rememberForever(string $key, callable $callback): mixed
    {
        $cacheKey = $this->getCacheKey($key);

        return Cache::rememberForever($cacheKey, $callback);
    }

    /**
     * Forget a cached value.
     *
     * @param string $key
     * @return bool
     */
    protected function forget(string $key): bool
    {
        $cacheKey = $this->getCacheKey($key);

        return Cache::forget($cacheKey);
    }

    /**
     * Clear all cache for this model/service.
     *
     * @return bool
     */
    protected function clearCache(): bool
    {
        return Cache::forget($this->cachePrefix . ':*');
    }

    /**
     * Get full cache key.
     *
     * @param string $key
     * @return string
     */
    protected function getCacheKey(string $key): string
    {
        return "{$this->cachePrefix}:{$key}";
    }

    /**
     * Generate cache key for a specific branch.
     *
     * @param int $branchId
     * @param string $key
     * @return string
     */
    protected function getBranchCacheKey(int $branchId, string $key): string
    {
        return "{$this->cachePrefix}:branch:{$branchId}:{$key}";
    }
}
