<?php

namespace Modules\Report\Services\Expense;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Report\Models\FactExpenseDaily;
use Modules\Report\Queries\ExpenseAnalyticsQuery;

class ExpenseAnalyticsService
{
    private const DEFAULT_CACHE_TTL = 300; // 5 minutes
    private const REALTIME_CACHE_TTL = 60; // 1 minute

    private int $cacheTtl = self::DEFAULT_CACHE_TTL;

    public function getSummary(?int $branchId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $cacheKey = $this->cacheKey('expense_summary', [
            'branch' => $branchId,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($branchId, $startDate, $endDate) {
            $query = new ExpenseAnalyticsQuery();
            return $query->forBranch($branchId)
                ->forDateRange($startDate, $endDate)
                ->getSummary();
        });
    }

    public function getByCategory(?int $branchId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $cacheKey = $this->cacheKey('expense_by_category', [
            'branch' => $branchId,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($branchId, $startDate, $endDate) {
            $query = new ExpenseAnalyticsQuery();
            return $query->forBranch($branchId)
                ->forDateRange($startDate, $endDate)
                ->getByCategory();
        });
    }

    public function getDailyTrend(?int $branchId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $cacheKey = $this->cacheKey('expense_daily_trend', [
            'branch' => $branchId,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($branchId, $startDate, $endDate) {
            $query = new ExpenseAnalyticsQuery();
            return $query->forBranch($branchId)
                ->forDateRange($startDate, $endDate)
                ->getDailyTrend();
        });
    }

    public function getOutletComparison(?string $startDate = null, ?string $endDate = null): array
    {
        $cacheKey = $this->cacheKey('expense_outlet_comparison', [
            'start' => $startDate,
            'end' => $endDate,
        ]);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate) {
            $query = new ExpenseAnalyticsQuery();
            return $query->forDateRange($startDate, $endDate)
                ->getOutletComparison();
        });
    }

    public function getCategoryWiseExpense(?int $branchId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = new ExpenseAnalyticsQuery();
        $data = $query->forBranch($branchId)
            ->forDateRange($startDate, $endDate)
            ->getByCategory();

        $total = collect($data)->sum('total_expenses');

        return collect($data)->map(function ($item) use ($total) {
            $percentage = $total > 0 ? ($item['total_expenses'] / $total) * 100 : 0;
            return [
                'expense_category_id' => $item['expense_category_id'],
                'total_expenses' => (float) $item['total_expenses'],
                'approved_expenses' => (float) $item['approved_expenses'],
                'total_transactions' => (int) $item['total_transactions'],
                'percentage' => round($percentage, 2),
                'currency' => $item['currency'],
            ];
        })->toArray();
    }

    private function cacheKey(string $prefix, array $params): string
    {
        $paramString = md5(json_encode($params));
        return makeCacheKey(['expense_analytics', $prefix, $paramString], false);
    }
}
