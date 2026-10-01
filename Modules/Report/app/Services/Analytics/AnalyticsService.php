<?php

namespace Modules\Report\Services\Analytics;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Report\Models\FactInventoryDaily;
use Modules\Report\Models\FactItemSalesHourly;
use Modules\Report\Models\FactSalesDaily;

class AnalyticsService
{
    private int $cacheTtl = 300; // 5 minutes

    public function getSalesAnalytics(
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
        ?int $branchId = null,
        ?string $groupBy = null
    ): array {
        $cacheKey = $this->cacheKey('sales_analytics', [
            'start' => $this->dateString($startDate),
            'end' => $this->dateString($endDate),
            'branch' => $branchId,
            'group_by' => $groupBy,
        ]);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate, $branchId, $groupBy) {
            $query = FactSalesDaily::query()
                ->forDateRange($this->dateString($startDate), $this->dateString($endDate));

            if ($branchId) {
                $query->forBranch($branchId);
            }

            if ($groupBy === 'branch') {
                $data = $query->selectRaw('
                    branch_id,
                    SUM(total_orders) as total_orders,
                    SUM(net_sales) as net_sales,
                    SUM(gross_sales) as gross_sales,
                    SUM(profit_total) as profit_total,
                    SUM(discount_total) as discount_total,
                    SUM(tax_total) as tax_total,
                    AVG(average_order_value) as avg_order_value
                ')
                    ->groupBy('branch_id')
                    ->get()
                    ->map(fn ($row) => [
                        'branch_id' => $row->branch_id,
                        'branch_name' => $row->branch?->name ?? 'Unknown',
                        'total_orders' => (int) $row->total_orders,
                        'net_sales' => (float) $row->net_sales,
                        'gross_sales' => (float) $row->gross_sales,
                        'profit_total' => (float) $row->profit_total,
                        'discount_total' => (float) $row->discount_total,
                        'tax_total' => (float) $row->tax_total,
                        'avg_order_value' => (float) $row->avg_order_value,
                    ])
                    ->values();
            } elseif ($groupBy === 'date') {
                $data = $query->selectRaw('
                    business_date,
                    SUM(total_orders) as total_orders,
                    SUM(net_sales) as net_sales,
                    SUM(gross_sales) as gross_sales,
                    SUM(profit_total) as profit_total
                ')
                    ->groupBy('business_date')
                    ->orderBy('business_date')
                    ->get()
                    ->map(fn ($row) => [
                        'date' => $row->business_date,
                        'total_orders' => (int) $row->total_orders,
                        'net_sales' => (float) $row->net_sales,
                        'gross_sales' => (float) $row->gross_sales,
                        'profit_total' => (float) $row->profit_total,
                    ])
                    ->values();
            } else {
                $data = $query->selectRaw('
                    SUM(total_orders) as total_orders,
                    SUM(net_sales) as net_sales,
                    SUM(gross_sales) as gross_sales,
                    SUM(profit_total) as profit_total,
                    SUM(discount_total) as discount_total,
                    SUM(tax_total) as tax_total,
                    AVG(average_order_value) as avg_order_value,
                    SUM(unique_customers) as unique_customers,
                    SUM(new_customers) as new_customers
                ')
                    ->first();

                $data = [
                    'total_orders' => (int) ($data->total_orders ?? 0),
                    'net_sales' => (float) ($data->net_sales ?? 0),
                    'gross_sales' => (float) ($data->gross_sales ?? 0),
                    'profit_total' => (float) ($data->profit_total ?? 0),
                    'discount_total' => (float) ($data->discount_total ?? 0),
                    'tax_total' => (float) ($data->tax_total ?? 0),
                    'avg_order_value' => (float) ($data->avg_order_value ?? 0),
                    'unique_customers' => (int) ($data->unique_customers ?? 0),
                    'new_customers' => (int) ($data->new_customers ?? 0),
                ];
            }

            return [
                'period' => [
                    'start' => $this->dateString($startDate),
                    'end' => $this->dateString($endDate),
                ],
                'data' => $data,
                'currency' => setting('default_currency') ?? 'INR',
            ];
        });
    }

    public function getPeakHours(
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
        ?int $branchId = null,
        ?int $productId = null
    ): Collection {
        $cacheKey = $this->cacheKey('peak_hours', [
            'start' => $this->dateString($startDate),
            'end' => $this->dateString($endDate),
            'branch' => $branchId,
            'product' => $productId,
        ]);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate, $branchId, $productId) {
            $query = FactItemSalesHourly::query()
                ->forDateRange($this->dateString($startDate), $this->dateString($endDate));

            if ($branchId) {
                $query->forBranch($branchId);
            }

            if ($productId) {
                $query->forProduct($productId);
            }

            return $query->selectRaw('
                hour,
                SUM(quantity_sold) as total_quantity,
                SUM(net_sales) as total_sales,
                SUM(order_count) as total_orders
            ')
                ->groupBy('hour')
                ->orderByDesc('total_quantity')
                ->limit(24)
                ->get()
                ->map(fn ($row) => [
                    'hour' => (int) $row->hour,
                    'hour_label' => sprintf('%02d:00', $row->hour),
                    'total_quantity' => (int) $row->total_quantity,
                    'total_sales' => (float) $row->total_sales,
                    'total_orders' => (int) $row->total_orders,
                ])
                ->sortBy('hour')
                ->values();
        });
    }

    public function getOutletComparison(
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate
    ): Collection {
        $cacheKey = $this->cacheKey('outlet_comparison', [
            'start' => $this->dateString($startDate),
            'end' => $this->dateString($endDate),
        ]);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate) {
            return FactSalesDaily::query()
                ->forDateRange($this->dateString($startDate), $this->dateString($endDate))
                ->selectRaw('
                    branch_id,
                    MAX(branches.name) as branch_name,
                    SUM(total_orders) as total_orders,
                    SUM(net_sales) as net_sales,
                    SUM(gross_sales) as gross_sales,
                    SUM(profit_total) as profit_total,
                    AVG(average_order_value) as avg_order_value,
                    SUM(unique_customers) as unique_customers
                ')
                ->leftJoin('branches', 'branches.id', '=', 'fact_sales_dailies.branch_id')
                ->groupBy('branch_id')
                ->orderByDesc('net_sales')
                ->get()
                ->map(fn ($row) => [
                    'branch_id' => $row->branch_id,
                    'branch_name' => $row->branch_name,
                    'total_orders' => (int) $row->total_orders,
                    'net_sales' => (float) $row->net_sales,
                    'gross_sales' => (float) $row->gross_sales,
                    'profit_total' => (float) $row->profit_total,
                    'avg_order_value' => (float) $row->avg_order_value,
                    'unique_customers' => (int) $row->unique_customers,
                    'profit_margin' => $row->gross_sales > 0
                        ? round(($row->profit_total / $row->gross_sales) * 100, 2)
                        : 0,
                ])
                ->values();
        });
    }

    public function getMenuEngineeringMetrics(
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
        ?int $branchId = null
    ): Collection {
        $cacheKey = $this->cacheKey('menu_engineering', [
            'start' => $this->dateString($startDate),
            'end' => $this->dateString($endDate),
            'branch' => $branchId,
        ]);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate, $branchId) {
            $query = FactItemSalesHourly::query()
                ->forDateRange($this->dateString($startDate), $this->dateString($endDate));

            if ($branchId) {
                $query->forBranch($branchId);
            }

            $totalSales = (clone $query)->sum('net_sales');
            $totalQuantity = (clone $query)->sum('quantity_sold');

            return $query->selectRaw('
                product_id,
                category_id,
                SUM(quantity_sold) as total_quantity,
                SUM(net_sales) as total_sales,
                SUM(cost_total) as total_cost,
                SUM(profit_total) as total_profit,
                AVG(margin_percent) as avg_margin,
                SUM(order_count) as total_orders
            ')
                ->groupBy('product_id', 'category_id')
                ->get()
                ->map(fn ($row) => [
                    'product_id' => $row->product_id,
                    'category_id' => $row->category_id,
                    'total_quantity' => (int) $row->total_quantity,
                    'total_sales' => (float) $row->total_sales,
                    'total_cost' => (float) $row->total_cost,
                    'total_profit' => (float) $row->total_profit,
                    'avg_margin' => (float) $row->avg_margin,
                    'total_orders' => (int) $row->total_orders,
                    'sales_contribution' => $totalSales > 0
                        ? round(($row->total_sales / $totalSales) * 100, 2)
                        : 0,
                    'quantity_contribution' => $totalQuantity > 0
                        ? round(($row->total_quantity / $totalQuantity) * 100, 2)
                        : 0,
                    'classification' => $this->classifyMenuItem(
                        $row->total_sales / max($totalSales, 1),
                        $row->total_quantity / max($totalQuantity, 1)
                    ),
                ])
                ->values();
        });
    }

    public function getInventoryAnalytics(
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
        ?int $branchId = null
    ): array {
        $cacheKey = $this->cacheKey('inventory_analytics', [
            'start' => $this->dateString($startDate),
            'end' => $this->dateString($endDate),
            'branch' => $branchId,
        ]);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate, $branchId) {
            $query = FactInventoryDaily::query()
                ->forDateRange($this->dateString($startDate), $this->dateString($endDate));

            if ($branchId) {
                $query->forBranch($branchId);
            }

            $summary = $query->selectRaw('
                SUM(purchased_qty) as total_purchased,
                SUM(consumed_qty) as total_consumed,
                SUM(wasted_qty) as total_wasted,
                SUM(purchase_value) as total_purchase_value,
                SUM(consumed_value) as total_consumed_value,
                SUM(wasted_value) as total_wasted_value
            ')
                ->first();

            $lowStockItems = (clone $query)
                ->lowStock(10)
                ->count();

            $highWastageItems = (clone $query)
                ->highWastage(5)
                ->count();

            return [
                'period' => [
                    'start' => $this->dateString($startDate),
                    'end' => $this->dateString($endDate),
                ],
                'summary' => [
                    'total_purchased' => (float) ($summary->total_purchased ?? 0),
                    'total_consumed' => (float) ($summary->total_consumed ?? 0),
                    'total_wasted' => (float) ($summary->total_wasted ?? 0),
                    'total_purchase_value' => (float) ($summary->total_purchase_value ?? 0),
                    'total_consumed_value' => (float) ($summary->total_consumed_value ?? 0),
                    'total_wasted_value' => (float) ($summary->total_wasted_value ?? 0),
                    'wastage_percentage' => $summary->total_purchased > 0
                        ? round(($summary->total_wasted / $summary->total_purchased) * 100, 2)
                        : 0,
                ],
                'alerts' => [
                    'low_stock_items' => $lowStockItems,
                    'high_wastage_items' => $highWastageItems,
                ],
                'currency' => setting('default_currency') ?? 'INR',
            ];
        });
    }

    public function getRealtimeDashboard(?int $branchId = null): array
    {
        $cacheKey = $this->cacheKey('realtime_dashboard', ['branch' => $branchId]);
        $cacheTtl = 60; // 1 minute for realtime

        return Cache::remember($cacheKey, $cacheTtl, function () use ($branchId) {
            $today = now()->toDateString();

            $query = FactSalesDaily::query()
                ->forDate($today);

            if ($branchId) {
                $query->forBranch($branchId);
            }

            $todayData = $query->first();

            $yesterday = now()->subDay()->toDateString();
            $yesterdayQuery = FactSalesDaily::query()
                ->forDate($yesterday);

            if ($branchId) {
                $yesterdayQuery->forBranch($branchId);
            }

            $yesterdayData = $yesterdayQuery->first();

            $salesGrowth = $yesterdayData && $yesterdayData->net_sales > 0
                ? round((($todayData->net_sales ?? 0) - $yesterdayData->net_sales) / $yesterdayData->net_sales * 100, 2)
                : 0;

            $ordersGrowth = $yesterdayData && $yesterdayData->total_orders > 0
                ? round((($todayData->total_orders ?? 0) - $yesterdayData->total_orders) / $yesterdayData->total_orders * 100, 2)
                : 0;

            return [
                'today' => [
                    'date' => $today,
                    'total_orders' => (int) ($todayData->total_orders ?? 0),
                    'net_sales' => (float) ($todayData->net_sales ?? 0),
                    'gross_sales' => (float) ($todayData->gross_sales ?? 0),
                    'profit_total' => (float) ($todayData->profit_total ?? 0),
                    'unique_customers' => (int) ($todayData->unique_customers ?? 0),
                    'average_order_value' => (float) ($todayData->average_order_value ?? 0),
                ],
                'yesterday' => [
                    'date' => $yesterday,
                    'total_orders' => (int) ($yesterdayData->total_orders ?? 0),
                    'net_sales' => (float) ($yesterdayData->net_sales ?? 0),
                ],
                'growth' => [
                    'sales_growth_percent' => $salesGrowth,
                    'orders_growth_percent' => $ordersGrowth,
                ],
                'currency' => $todayData->currency ?? setting('default_currency') ?? 'INR',
                'generated_at' => now()->toIso8601String(),
            ];
        });
    }

    private function cacheKey(string $prefix, array $params): string
    {
        $actor = auth()->user();
        $tenantScope = $actor?->isSuperAdmin()
            ? 'platform'
            : 'tenant:'.($actor?->tenantId() ?? 'none');
        $paramString = md5(json_encode($params));

        return makeCacheKey(['analytics', $tenantScope, $prefix, $paramString], false);
    }

    private function dateString(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface
            ? $date->toDateString()
            : Carbon::parse($date)->toDateString();
    }

    private function classifyMenuItem(float $salesContribution, float $quantityContribution): string
    {
        // Menu Engineering Matrix (Boston Matrix style)
        // Stars: High sales, High quantity
        // Plowhorses: High sales, Low quantity
        // Puzzles: Low sales, High quantity
        // Dogs: Low sales, Low quantity

        $highThreshold = 0.2; // Top 20%
        $lowThreshold = 0.1; // Bottom 10%

        if ($salesContribution >= $highThreshold && $quantityContribution >= $highThreshold) {
            return 'star';
        }

        if ($salesContribution >= $highThreshold && $quantityContribution <= $lowThreshold) {
            return 'plowhorse';
        }

        if ($salesContribution <= $lowThreshold && $quantityContribution >= $highThreshold) {
            return 'puzzle';
        }

        return 'dog';
    }
}
