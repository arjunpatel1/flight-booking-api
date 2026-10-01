<?php

namespace Modules\FinancialDashboard\Services;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Enums\PaymentType;
use Modules\Report\Models\FactSalesDaily;
use Modules\Report\Queries\ItemSalesQuery;
use Modules\Report\Services\Analytics\AnalyticsService;

class FinancialDashboardService
{
    private int $cacheTtl = 300;

    public function __construct(protected AnalyticsService $analytics) {}

    private function unwrap(array $wrapped): array
    {
        return is_array($wrapped['data'] ?? null) ? $wrapped['data'] : ($wrapped['data'] ?? []);
    }

    /**
     * Daily fact tables are populated asynchronously. A financial screen must not
     * report zero while a recently completed order is waiting for aggregation.
     */
    private function salesData(string $startDate, string $endDate, ?int $branchId = null): array
    {
        $data = $this->unwrap($this->analytics->getSalesAnalytics($startDate, $endDate, $branchId));

        $totals = Order::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->withoutCanceledOrders()
            ->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59'])
            ->selectRaw('
                COUNT(*) as total_orders,
                COALESCE(SUM(subtotal * COALESCE(currency_rate, 1)), 0) as gross_sales,
                COALESCE(SUM(total * COALESCE(currency_rate, 1)), 0) as net_sales,
                COUNT(DISTINCT customer_id) as unique_customers
            ')
            ->first();

        if ((int) ($totals?->total_orders ?? 0) === 0) {
            return $data;
        }

        $netSales = (float) $totals->net_sales;
        $orders = (int) $totals->total_orders;

        return array_merge($data, [
            'total_orders' => $orders,
            'gross_sales' => (float) $totals->gross_sales,
            'net_sales' => $netSales,
            'avg_order_value' => $orders > 0 ? round($netSales / $orders, 4) : 0,
            'unique_customers' => (int) $totals->unique_customers,
            'new_customers' => 0,
            'tax_total' => (float) ($data['tax_total'] ?? 0),
            'discount_total' => (float) ($data['discount_total'] ?? 0),
            'profit_total' => (float) ($data['profit_total'] ?? $netSales),
            'source' => 'live_orders',
        ]);
    }

    public function getKpis(string $startDate, string $endDate, ?int $branchId = null): array
    {
        $cacheKey = $this->cacheKey('kpis', compact('startDate', 'endDate', 'branchId'));

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate, $branchId) {
            $today = today()->toDateString();
            $yesterday = today()->subDay()->toDateString();
            $mtdStart = today()->startOfMonth()->toDateString();
            $ytdStart = today()->startOfYear()->toDateString();

            $todayData = $this->salesData($today, $today, $branchId);
            $yesterdayData = $this->salesData($yesterday, $yesterday, $branchId);
            $mtdData = $this->salesData($mtdStart, $today, $branchId);
            $ytdData = $this->salesData($ytdStart, $today, $branchId);
            $rangeData = $this->salesData($startDate, $endDate, $branchId);

            $cancelledQuery = Order::query()
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);

            $cancelled = (clone $cancelledQuery)->where('status', 'cancelled')->count();
            $refundAmount = (clone $cancelledQuery)->where('status', 'refunded')
                ->sum(DB::raw('total * COALESCE(currency_rate, 1)'));
            $outstanding = (clone $cancelledQuery)
                ->whereNotIn('status', ['completed', 'cancelled', 'refunded'])
                ->sum(DB::raw('total * COALESCE(currency_rate, 1)'));

            $diffDays = max(1, Carbon::parse($endDate)->diffInDays($startDate) + 1);
            $prevEnd = Carbon::parse($startDate)->subDay()->toDateString();
            $prevStart = Carbon::parse($startDate)->subDays($diffDays)->toDateString();
            $prevData = $this->salesData($prevStart, $prevEnd, $branchId);
            $prevSales = max(1.0, (float) ($prevData['net_sales'] ?? 1));
            $currSales = (float) ($rangeData['net_sales'] ?? 0);
            $growth = round((($currSales - $prevSales) / $prevSales) * 100, 1);

            return [
                'today' => [
                    'net_sales' => (float) ($todayData['net_sales'] ?? 0),
                    'gross_sales' => (float) ($todayData['gross_sales'] ?? 0),
                    'orders' => (int) ($todayData['total_orders'] ?? 0),
                    'avg_order_value' => (float) ($todayData['avg_order_value'] ?? 0),
                    'tax_collected' => (float) ($todayData['tax_total'] ?? 0),
                    'discounts' => (float) ($todayData['discount_total'] ?? 0),
                ],
                'yesterday' => [
                    'net_sales' => (float) ($yesterdayData['net_sales'] ?? 0),
                    'orders' => (int) ($yesterdayData['total_orders'] ?? 0),
                ],
                'mtd' => [
                    'net_sales' => (float) ($mtdData['net_sales'] ?? 0),
                    'gross_sales' => (float) ($mtdData['gross_sales'] ?? 0),
                    'orders' => (int) ($mtdData['total_orders'] ?? 0),
                    'tax_collected' => (float) ($mtdData['tax_total'] ?? 0),
                    'profit' => (float) ($mtdData['profit_total'] ?? 0),
                ],
                'ytd' => [
                    'net_sales' => (float) ($ytdData['net_sales'] ?? 0),
                    'gross_sales' => (float) ($ytdData['gross_sales'] ?? 0),
                    'orders' => (int) ($ytdData['total_orders'] ?? 0),
                    'profit' => (float) ($ytdData['profit_total'] ?? 0),
                    'customers' => (int) ($ytdData['unique_customers'] ?? 0),
                ],
                'period' => [
                    'net_sales' => $currSales,
                    'gross_sales' => (float) ($rangeData['gross_sales'] ?? 0),
                    'orders' => (int) ($rangeData['total_orders'] ?? 0),
                    'profit' => (float) ($rangeData['profit_total'] ?? 0),
                    'discounts' => (float) ($rangeData['discount_total'] ?? 0),
                    'tax_collected' => (float) ($rangeData['tax_total'] ?? 0),
                    'avg_order_value' => (float) ($rangeData['avg_order_value'] ?? 0),
                    'unique_customers' => (int) ($rangeData['unique_customers'] ?? 0),
                    'new_customers' => (int) ($rangeData['new_customers'] ?? 0),
                    'sales_growth_pct' => $growth,
                    'cancelled_orders' => $cancelled,
                    'refund_amount' => (float) $refundAmount,
                    'outstanding_amount' => (float) $outstanding,
                ],
            ];
        });
    }

    public function getSalesTrend(string $startDate, string $endDate, ?int $branchId = null, string $groupBy = 'date'): array
    {
        $cacheKey = $this->cacheKey('trend', compact('startDate', 'endDate', 'branchId', 'groupBy'));

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate, $branchId, $groupBy) {
            if (in_array($groupBy, ['week', 'month', 'year'])) {
                return $this->aggregateTrend($startDate, $endDate, $branchId, $groupBy);
            }

            $data = Order::query()
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->withoutCanceledOrders()
                ->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59'])
                ->selectRaw('
                        DATE(created_at) as date,
                        COUNT(*) as total_orders,
                        COALESCE(SUM(subtotal * COALESCE(currency_rate, 1)), 0) as gross_sales,
                        COALESCE(SUM(total * COALESCE(currency_rate, 1)), 0) as net_sales
                    ')
                ->groupByRaw('DATE(created_at)')
                ->orderBy('date')
                ->get()
                ->map(fn ($row) => [
                    'date' => $row->date,
                    'total_orders' => (int) $row->total_orders,
                    'gross_sales' => (float) $row->gross_sales,
                    'net_sales' => (float) $row->net_sales,
                    'profit_total' => (float) $row->net_sales,
                ]);

            return is_array($data) ? $data : $data->values()->all();
        });
    }

    private function aggregateTrend(string $startDate, string $endDate, ?int $branchId, string $period): array
    {
        $rows = collect($this->getSalesTrend($startDate, $endDate, $branchId, 'date'));

        return $rows->groupBy(function ($row) use ($period) {
            $date = Carbon::parse($row['date']);

            return match ($period) {
                'week' => $date->format('Y').'-W'.$date->format('W'),
                'month' => $date->format('Y-m'),
                'year' => $date->format('Y'),
            };
        })->map(function ($days, $key) {
            return [
                'date' => $days->first()['date'],
                'period' => $key,
                'net_sales' => round($days->sum('net_sales'), 2),
                'gross_sales' => round($days->sum('gross_sales'), 2),
                'total_orders' => $days->sum('total_orders'),
                'profit_total' => round($days->sum('profit_total'), 2),
            ];
        })->values()->all();
    }

    public function getBranchAnalytics(string $startDate, string $endDate): array
    {
        $cacheKey = $this->cacheKey('branch', compact('startDate', 'endDate'));

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate) {
            $rows = $this->analytics->getOutletComparison($startDate, $endDate);

            if ($rows->isEmpty() || (float) $rows->sum('net_sales') <= 0) {
                $rows = collect($this->liveBranchAnalytics($startDate, $endDate));
            }

            $totalSales = max(1.0, (float) $rows->sum('net_sales'));

            return $rows->map(fn ($row) => [
                ...$row,
                'branch_name' => $this->translatedDatabaseValue(
                    $row['branch_name'] ?? null,
                    'Branch #'.($row['branch_id'] ?? ''),
                ),
                'contribution_pct' => round(((float) ($row['net_sales'] ?? 0) / $totalSales) * 100, 1),
                'profit_margin_pct' => (float) ($row['profit_margin'] ?? $row['profit_margin_pct'] ?? 0),
            ])->values()->all();
        });
    }

    private function liveBranchAnalytics(string $startDate, string $endDate): array
    {
        $query = DB::table('orders')
            ->leftJoin('branches', 'branches.id', '=', 'orders.branch_id')
            ->whereNotIn('orders.status', Order::revenueExcludedStatuses())
            ->whereBetween('orders.created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59'])
            ->selectRaw("
                orders.branch_id,
                COALESCE(branches.name, CONCAT('Branch #', orders.branch_id)) as branch_name,
                COUNT(*) as total_orders,
                COALESCE(SUM(orders.subtotal * COALESCE(orders.currency_rate, 1)), 0) as gross_sales,
                COALESCE(SUM(orders.total * COALESCE(orders.currency_rate, 1)), 0) as net_sales,
                COALESCE(AVG(orders.total * COALESCE(orders.currency_rate, 1)), 0) as avg_order_value,
                0 as profit_margin
            ")
            ->groupBy('orders.branch_id', 'branches.name')
            ->orderByDesc('net_sales');

        return $this->scopeRawQueryToTenant($query, 'orders.branch_id')
            ->get()
            ->map(fn ($row) => [
                'branch_id' => (int) $row->branch_id,
                'branch_name' => $this->translatedDatabaseValue($row->branch_name, 'Branch #'.$row->branch_id),
                'total_orders' => (int) $row->total_orders,
                'gross_sales' => (float) $row->gross_sales,
                'net_sales' => (float) $row->net_sales,
                'avg_order_value' => (float) $row->avg_order_value,
                'profit_margin' => (float) $row->profit_margin,
            ])
            ->values()
            ->all();
    }

    public function getProfitability(string $startDate, string $endDate, ?int $branchId = null): array
    {
        $cacheKey = $this->cacheKey('profit', compact('startDate', 'endDate', 'branchId'));

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate, $branchId) {
            $data = $this->salesData($startDate, $endDate, $branchId);
            $grossSales = max(1.0, (float) ($data['gross_sales'] ?? 1));
            $netSales = (float) ($data['net_sales'] ?? 0);
            $profit = (float) ($data['profit_total'] ?? 0);
            $discounts = (float) ($data['discount_total'] ?? 0);
            $taxes = (float) ($data['tax_total'] ?? 0);
            $cogs = max(0.0, $netSales - $profit);
            $expenses = 0.0;

            try {
                $expensesQuery = DB::table('expenses')
                    ->whereBetween('expense_date', [$startDate, $endDate])
                    ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
                $expenses = (float) $this->scopeRawQueryToTenant($expensesQuery, 'expenses.branch_id')->sum('amount');
            } catch (\Throwable) {
            }

            $netProfit = $profit - $expenses;

            return [
                'gross_revenue' => $grossSales,
                'net_revenue' => $netSales,
                'gross_profit' => $profit,
                'net_profit' => $netProfit,
                'cogs' => $cogs,
                'expenses' => $expenses,
                'discounts' => $discounts,
                'tax_collected' => $taxes,
                'food_cost_pct' => $grossSales > 0 ? round(($cogs / $grossSales) * 100, 1) : 0,
                'expense_pct' => $netSales > 0 ? round(($expenses / $netSales) * 100, 1) : 0,
                'discount_pct' => $grossSales > 0 ? round(($discounts / $grossSales) * 100, 1) : 0,
                'tax_pct' => $grossSales > 0 ? round(($taxes / $grossSales) * 100, 1) : 0,
                'profit_margin_pct' => $grossSales > 0 ? round(($profit / $grossSales) * 100, 1) : 0,
                'net_profit_margin_pct' => $netSales > 0 ? round(($netProfit / $netSales) * 100, 1) : 0,
            ];
        });
    }

    public function getCategoryAnalytics(string $startDate, string $endDate, ?int $branchId = null): array
    {
        $cacheKey = $this->cacheKey('category', compact('startDate', 'endDate', 'branchId'));

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate, $branchId) {
            try {
                $rows = (new ItemSalesQuery)
                    ->forDateRange($startDate, $endDate)
                    ->forBranch($branchId)
                    ->getCategoryPerformance()
                    ->sortByDesc('total_sales')
                    ->take(15)
                    ->values();

                if ($rows->isNotEmpty() && (float) $rows->sum('total_sales') > 0) {
                    return $rows->all();
                }
            } catch (\Throwable) {
                // Fall back to live order lines when analytics snapshots are not available yet.
            }

            return $this->liveCategoryAnalytics($startDate, $endDate, $branchId);
        });
    }

    private function liveCategoryAnalytics(string $startDate, string $endDate, ?int $branchId = null): array
    {
        $query = DB::table('orders')
            ->join('order_products as order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('product_categories', 'product_categories.product_id', '=', 'products.id')
            ->leftJoin('categories', 'categories.id', '=', 'product_categories.category_id')
            ->whereNotIn('orders.status', Order::revenueExcludedStatuses())
            ->whereBetween('orders.created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59'])
            ->when($branchId, fn ($query) => $query->where('orders.branch_id', $branchId))
            ->selectRaw("
                COALESCE(categories.id, 0) as category_id,
                COALESCE(categories.name, 'Uncategorized') as category_name,
                COUNT(DISTINCT orders.id) as total_orders,
                COALESCE(SUM(order_items.quantity), 0) as quantity_sold,
                COALESCE(SUM(order_items.subtotal * COALESCE(order_items.currency_rate, orders.currency_rate, 1)), 0) as gross_sales,
                COALESCE(SUM(order_items.total * COALESCE(order_items.currency_rate, orders.currency_rate, 1)), 0) as total_sales
            ")
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('total_sales')
            ->limit(15);

        $rows = $this->scopeRawQueryToTenant($query, 'orders.branch_id')
            ->get();

        $grandTotal = max(1.0, (float) $rows->sum('total_sales'));

        return $rows->map(fn ($row) => [
            'category_id' => (int) $row->category_id,
            'category_name' => $row->category_name,
            'name' => $row->category_name,
            'total_orders' => (int) $row->total_orders,
            'quantity_sold' => (float) $row->quantity_sold,
            'gross_sales' => (float) $row->gross_sales,
            'total_sales' => (float) $row->total_sales,
            'contribution_pct' => round(((float) $row->total_sales / $grandTotal) * 100, 1),
        ])->values()->all();
    }

    public function getTopItems(string $startDate, string $endDate, ?int $branchId = null, int $limit = 15): array
    {
        $cacheKey = $this->cacheKey('top_items', compact('startDate', 'endDate', 'branchId', 'limit'));

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate, $branchId, $limit) {
            try {
                $rows = (new ItemSalesQuery)
                    ->forDateRange($startDate, $endDate)
                    ->forBranch($branchId)
                    ->getTopSellingProducts($limit)
                    ->values();

                if ($rows->isNotEmpty() && (float) $rows->sum('total_sales') > 0) {
                    return $rows->all();
                }
            } catch (\Throwable) {
                // Fall back to live order lines when analytics snapshots are not available yet.
            }

            return $this->liveTopItems($startDate, $endDate, $branchId, $limit);
        });
    }

    private function liveTopItems(string $startDate, string $endDate, ?int $branchId, int $limit): array
    {
        $query = DB::table('orders')
            ->join('order_products as order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->whereNotIn('orders.status', Order::revenueExcludedStatuses())
            ->whereBetween('orders.created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59'])
            ->when($branchId, fn ($query) => $query->where('orders.branch_id', $branchId))
            ->selectRaw("
                COALESCE(products.id, order_items.product_id) as product_id,
                COALESCE(products.name, CONCAT('Product #', order_items.product_id)) as product_name,
                products.sku,
                COUNT(DISTINCT orders.id) as order_count,
                COALESCE(SUM(order_items.quantity), 0) as quantity_sold,
                COALESCE(SUM(order_items.total * COALESCE(order_items.currency_rate, orders.currency_rate, 1)), 0) as total_sales,
                COALESCE(AVG(order_items.unit_price * COALESCE(order_items.currency_rate, orders.currency_rate, 1)), 0) as avg_price
            ")
            ->groupBy('products.id', 'order_items.product_id', 'products.name', 'products.sku')
            ->orderByDesc('total_sales')
            ->limit($limit);

        return $this->scopeRawQueryToTenant($query, 'orders.branch_id')
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'product_name' => $row->product_name,
                'name' => $row->product_name,
                'sku' => $row->sku,
                'order_count' => (int) $row->order_count,
                'quantity_sold' => (float) $row->quantity_sold,
                'total_quantity' => (float) $row->quantity_sold,
                'total_sales' => (float) $row->total_sales,
                'avg_price' => (float) $row->avg_price,
            ])
            ->values()
            ->all();
    }

    public function getMenuEngineering(string $startDate, string $endDate, ?int $branchId = null): array
    {
        return $this->analytics
            ->getMenuEngineeringMetrics($startDate, $endDate, $branchId)
            ->values()
            ->all();
    }

    public function getPaymentAnalytics(string $startDate, string $endDate, ?int $branchId = null): array
    {
        $cacheKey = $this->cacheKey('payments', compact('startDate', 'endDate', 'branchId'));

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate, $branchId) {
            $query = DB::table('payments')
                ->join('orders', 'orders.id', '=', 'payments.order_id')
                ->select(
                    'payments.method',
                    DB::raw('COUNT(*) as count'),
                    DB::raw('SUM(payments.amount * COALESCE(orders.currency_rate, 1)) as total')
                )
                ->whereBetween('payments.created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59'])
                ->where('payments.status', PaymentStatus::Completed->value)
                ->where('payments.type', PaymentType::Payment->value)
                ->whereNull('payments.deleted_at')
                ->where('orders.status', OrderStatus::Completed->value)
                ->where('orders.payment_status', OrderPaymentStatus::Paid->value)
                ->when($branchId, fn ($q) => $q->where('orders.branch_id', $branchId))
                ->groupBy('payments.method')
                ->orderByDesc('total');

            $rows = $this->scopeRawQueryToTenant($query, 'orders.branch_id')
                ->get();

            $grandTotal = max(1.0, (float) $rows->sum('total'));

            return $rows->map(fn ($row) => [
                'method' => $row->method,
                'count' => (int) $row->count,
                'total' => (float) $row->total,
                'contribution_pct' => round(($row->total / $grandTotal) * 100, 1),
            ])->values()->all();
        });
    }

    public function getCustomerAnalytics(string $startDate, string $endDate, ?int $branchId = null): array
    {
        $cacheKey = $this->cacheKey('customers', compact('startDate', 'endDate', 'branchId'));

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate, $branchId) {
            $data = $this->salesData($startDate, $endDate, $branchId);

            $topCustomers = Order::query()
                ->join('users', 'users.id', '=', 'orders.customer_id')
                ->select(
                    'users.name',
                    'users.phone',
                    DB::raw('COUNT(orders.id) as order_count'),
                    DB::raw('SUM(orders.total * COALESCE(orders.currency_rate, 1)) as total_spend')
                )
                ->whereNotNull('orders.customer_id')
                ->whereBetween('orders.created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59'])
                ->when($branchId, fn ($q) => $q->where('orders.branch_id', $branchId))
                ->withoutCanceledOrders()
                ->groupBy('users.id', 'users.name', 'users.phone')
                ->orderByDesc('total_spend')
                ->limit(20)
                ->get()
                ->map(fn ($row) => [
                    'name' => $row->name,
                    'phone' => $row->phone,
                    'order_count' => (int) $row->order_count,
                    'total_spend' => (float) $row->total_spend,
                    'avg_order_value' => $row->order_count > 0
                        ? round($row->total_spend / $row->order_count, 2)
                        : 0,
                ]);

            $unique = (int) ($data['unique_customers'] ?? 0);
            $newCust = (int) ($data['new_customers'] ?? 0);
            $returning = max(0, $unique - $newCust);

            return [
                'unique_customers' => $unique,
                'new_customers' => $newCust,
                'returning_customers' => $returning,
                'repeat_rate_pct' => $unique > 0 ? round(($returning / $unique) * 100, 1) : 0,
                'top_customers' => $topCustomers,
            ];
        });
    }

    public function getPeakHours(string $startDate, string $endDate, ?int $branchId = null): array
    {
        return $this->analytics->getPeakHours($startDate, $endDate, $branchId)->all();
    }

    public function getPeakDays(string $startDate, string $endDate, ?int $branchId = null): array
    {
        $cacheKey = $this->cacheKey('peak_days', compact('startDate', 'endDate', 'branchId'));

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate, $branchId) {
            $dayNames = ['', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

            try {
                return FactSalesDaily::query()
                    ->forDateRange($startDate, $endDate)
                    ->when($branchId, fn ($q) => $q->forBranch($branchId))
                    ->selectRaw('
                        DAYOFWEEK(business_date) as day_of_week,
                        SUM(total_orders) as total_orders,
                        SUM(net_sales) as total_sales,
                        AVG(net_sales) as avg_sales
                    ')
                    ->groupBy(DB::raw('DAYOFWEEK(business_date)'))
                    ->orderBy(DB::raw('DAYOFWEEK(business_date)'))
                    ->get()
                    ->map(fn ($row) => [
                        'day_of_week' => (int) $row->day_of_week,
                        'day_name' => $dayNames[(int) $row->day_of_week] ?? 'Unknown',
                        'total_orders' => (int) $row->total_orders,
                        'total_sales' => (float) $row->total_sales,
                        'avg_sales' => (float) $row->avg_sales,
                    ])
                    ->values()
                    ->all();
            } catch (\Throwable) {
                return [];
            }
        });
    }

    private function cacheKey(string $scope, array $parts): string
    {
        $actor = auth()->user();
        $isPlatformAdmin = $actor?->isSuperAdmin()
            && ! $actor->assignedToTenant()
            && ! $actor->assignedToBranch();
        $tenantScope = $isPlatformAdmin
            ? 'platform'
            : 'tenant:'.($actor?->tenantId() ?? 'none').':branch:'.($actor?->branchId() ?? 'all');

        return makeCacheKey(['financial_dashboard', $tenantScope, $scope, md5(json_encode($parts))], false);
    }

    private function scopeRawQueryToTenant(QueryBuilder $query, string $branchColumn): QueryBuilder
    {
        if ($branchColumn === 'orders.branch_id') {
            $query->whereNull('orders.deleted_at');
        }

        $actor = auth()->user();
        if ($actor?->isSuperAdmin() && ! $actor->assignedToTenant() && ! $actor->assignedToBranch()) {
            return $query;
        }

        if (! $actor?->assignedToTenant()) {
            return $query->whereRaw('1 = 0');
        }

        if ($actor->assignedToBranch()) {
            return $query->where($branchColumn, $actor->branchId());
        }

        return $query->whereIn(
            $branchColumn,
            Branch::query()->withoutGlobalScopes()->where('tenant_id', $actor->tenantId())->select('id'),
        );
    }

    private function translatedDatabaseValue(mixed $value, string $fallback): string
    {
        if (is_array($value)) {
            return (string) ($value[app()->getLocale()] ?? $value[config('app.fallback_locale')] ?? reset($value) ?: $fallback);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $this->translatedDatabaseValue($decoded, $fallback);
            }

            return trim($value) !== '' ? $value : $fallback;
        }

        return $fallback;
    }
}
