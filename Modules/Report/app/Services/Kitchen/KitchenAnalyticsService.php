<?php

namespace Modules\Report\Services\Kitchen;

use Illuminate\Support\Facades\Cache;
use Modules\Report\Queries\KitchenAnalyticsQuery;

class KitchenAnalyticsService
{
    private const DEFAULT_CACHE_TTL = 300; // 5 minutes
    private const REALTIME_CACHE_TTL = 60; // 1 minute

    private int $cacheTtl = self::DEFAULT_CACHE_TTL;

    public function getSummary(?int $branchId = null, ?int $stationId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $cacheKey = $this->cacheKey('kitchen_summary', [
            'branch' => $branchId,
            'station' => $stationId,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($branchId, $stationId, $startDate, $endDate) {
            $query = new KitchenAnalyticsQuery();
            return $query->forBranch($branchId)
                ->forStation($stationId)
                ->forDateRange($startDate, $endDate)
                ->getSummary();
        });
    }

    public function getByStation(?int $branchId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $cacheKey = $this->cacheKey('kitchen_by_station', [
            'branch' => $branchId,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($branchId, $startDate, $endDate) {
            $query = new KitchenAnalyticsQuery();
            return $query->forBranch($branchId)
                ->forDateRange($startDate, $endDate)
                ->getByStation();
        });
    }

    public function getDailyTrend(?int $branchId = null, ?int $stationId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $cacheKey = $this->cacheKey('kitchen_daily_trend', [
            'branch' => $branchId,
            'station' => $stationId,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($branchId, $stationId, $startDate, $endDate) {
            $query = new KitchenAnalyticsQuery();
            return $query->forBranch($branchId)
                ->forStation($stationId)
                ->forDateRange($startDate, $endDate)
                ->getDailyTrend();
        });
    }

    public function getPeakHours(?int $branchId = null, ?int $stationId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $cacheKey = $this->cacheKey('kitchen_peak_hours', [
            'branch' => $branchId,
            'station' => $stationId,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($branchId, $stationId, $startDate, $endDate) {
            $query = new KitchenAnalyticsQuery();
            return $query->forBranch($branchId)
                ->forStation($stationId)
                ->forDateRange($startDate, $endDate)
                ->getPeakHours();
        });
    }

    private function cacheKey(string $prefix, array $params): string
    {
        $paramString = md5(json_encode($params));
        return makeCacheKey(['kitchen_analytics', $prefix, $paramString], false);
    }
}
