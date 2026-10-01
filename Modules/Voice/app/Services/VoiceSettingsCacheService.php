<?php

namespace Modules\Voice\Services;

use Illuminate\Support\Facades\Cache;
use Modules\Voice\Models\VoiceSetting;

class VoiceSettingsCacheService
{
    private const CACHE_PREFIX = 'voice_settings:';
    private const CACHE_BRANCHES_KEY = 'voice_settings:branches';
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Get voice settings from cache or database
     */
    public function getSettings(int $branchId): ?VoiceSetting
    {
        $cacheKey = $this->getCacheKey($branchId);
        $this->rememberBranchKey($branchId);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($branchId) {
            return VoiceSetting::where('branch_id', $branchId)->first();
        });
    }

    /**
     * Save voice settings and update cache
     */
    public function saveSettings(int $branchId, array $data): VoiceSetting
    {
        $this->rememberBranchKey($branchId);

        $settings = VoiceSetting::updateOrCreate(
            ['branch_id' => $branchId],
            $data
        );

        // Update cache
        $this->invalidateCache($branchId);

        return $settings;
    }

    /**
     * Invalidate cache for a branch
     */
    public function invalidateCache(int $branchId): void
    {
        $cacheKey = $this->getCacheKey($branchId);
        Cache::forget($cacheKey);
    }

    /**
     * Invalidate all voice settings cache
     */
    public function invalidateAllCache(): void
    {
        foreach (Cache::get(self::CACHE_BRANCHES_KEY, []) as $branchId) {
            $this->invalidateCache((int) $branchId);
        }

        Cache::forget(self::CACHE_BRANCHES_KEY);
    }

    /**
     * Get cache key for branch
     */
    private function getCacheKey(int $branchId): string
    {
        return self::CACHE_PREFIX . $branchId;
    }

    /**
     * Track branch cache keys so non-taggable cache stores can invalidate them.
     */
    private function rememberBranchKey(int $branchId): void
    {
        $branchIds = Cache::get(self::CACHE_BRANCHES_KEY, []);
        $branchIds[] = $branchId;

        Cache::put(self::CACHE_BRANCHES_KEY, array_values(array_unique($branchIds)), self::CACHE_TTL);
    }

    /**
     * Warm up cache for multiple branches
     */
    public function warmupCache(array $branchIds): void
    {
        foreach ($branchIds as $branchId) {
            $this->getSettings($branchId);
        }
    }
}
