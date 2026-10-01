<?php

namespace Modules\Report\Services\Shift;

use Illuminate\Support\Facades\Cache;
use Modules\Report\Queries\ShiftAnalyticsQuery;

class ShiftAnalyticsService
{
    private const DEFAULT_CACHE_TTL = 300; // 5 minutes
    private const REALTIME_CACHE_TTL = 60; // 1 minute

    private int $cacheTtl = self::DEFAULT_CACHE_TTL;

    public function getSummary(?int $branchId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $cacheKey = $this->cacheKey('shift_summary', [
            'branch' => $branchId,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($branchId, $startDate, $endDate) {
            $query = new ShiftAnalyticsQuery();
            return $query->forBranch($branchId)
                ->forDateRange($startDate, $endDate)
                ->getSummary();
        });
    }

    public function getByShift(?int $branchId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $cacheKey = $this->cacheKey('shift_by_shift', [
            'branch' => $branchId,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($branchId, $startDate, $endDate) {
            $query = new ShiftAnalyticsQuery();
            return $query->forBranch($branchId)
                ->forDateRange($startDate, $endDate)
                ->getByShift();
        });
    }

    public function getByUser(?int $branchId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $cacheKey = $this->cacheKey('shift_by_user', [
            'branch' => $branchId,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($branchId, $startDate, $endDate) {
            $query = new ShiftAnalyticsQuery();
            return $query->forBranch($branchId)
                ->forDateRange($startDate, $endDate)
                ->getByUser();
        });
    }

    public function getDailyTrend(?int $branchId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $cacheKey = $this->cacheKey('shift_daily_trend', [
            'branch' => $branchId,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($branchId, $startDate, $endDate) {
            $query = new ShiftAnalyticsQuery();
            return $query->forBranch($branchId)
                ->forDateRange($startDate, $endDate)
                ->getDailyTrend();
        });
    }

    public function getCashReconciliation(?int $branchId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $cacheKey = $this->cacheKey('shift_cash_reconciliation', [
            'branch' => $branchId,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($branchId, $startDate, $endDate) {
            $query = new ShiftAnalyticsQuery();
            return $query->forBranch($branchId)
                ->forDateRange($startDate, $endDate)
                ->getCashReconciliation();
        });
    }

    private function cacheKey(string $prefix, array $params): string
    {
        $paramString = md5(json_encode($params));
        return makeCacheKey(['shift_analytics', $prefix, $paramString], false);
    }
}
