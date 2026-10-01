<?php

namespace Modules\Report\Queries;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\Report\Models\FactItemSalesHourly;

class ItemSalesQuery
{
    private Builder $query;

    public function __construct()
    {
        $this->query = FactItemSalesHourly::query();
    }

    public function forDateRange(CarbonInterface|string $startDate, CarbonInterface|string $endDate): self
    {
        $start = $startDate instanceof CarbonInterface ? $startDate->toDateString() : Carbon::parse($startDate)->toDateString();
        $end = $endDate instanceof CarbonInterface ? $endDate->toDateString() : Carbon::parse($endDate)->toDateString();

        $this->query->whereBetween('business_date', [$start, $end]);

        return $this;
    }

    public function forBranch(?int $branchId): self
    {
        if ($branchId) {
            $this->query->where('branch_id', $branchId);
        }

        return $this;
    }

    public function forProduct(?int $productId): self
    {
        if ($productId) {
            $this->query->where('product_id', $productId);
        }

        return $this;
    }

    public function forCategory(?int $categoryId): self
    {
        if ($categoryId) {
            $this->query->where('category_id', $categoryId);
        }

        return $this;
    }

    public function forHour(?int $hour): self
    {
        if ($hour !== null) {
            $this->query->where('hour', $hour);
        }

        return $this;
    }

    public function groupByProduct(): self
    {
        $this->query->groupBy('product_id', 'category_id');

        return $this;
    }

    public function groupByHour(): self
    {
        $this->query->groupBy('hour');

        return $this;
    }

    public function groupByCategory(): self
    {
        $this->query->groupBy('category_id');

        return $this;
    }

    public function orderBySales(string $direction = 'desc'): self
    {
        $this->query->orderBy('net_sales', $direction);

        return $this;
    }

    public function orderByQuantity(string $direction = 'desc'): self
    {
        $this->query->orderBy('quantity_sold', $direction);

        return $this;
    }

    public function getPeakHours(int $limit = 5): \Illuminate\Support\Collection
    {
        return $this->query->selectRaw('
            hour,
            SUM(quantity_sold) as total_quantity,
            SUM(net_sales) as total_sales,
            SUM(order_count) as total_orders
        ')
        ->groupBy('hour')
        ->orderByDesc('total_quantity')
        ->limit($limit)
        ->get()
        ->map(fn($row) => [
            'hour' => (int) $row->hour,
            'hour_label' => sprintf('%02d:00', $row->hour),
            'total_quantity' => (int) $row->total_quantity,
            'total_sales' => (float) $row->total_sales,
            'total_orders' => (int) $row->total_orders,
        ])
        ->sortBy('hour')
        ->values();
    }

    public function getProductPerformance(): \Illuminate\Support\Collection
    {
        $totalSales = (clone $this->query)->sum('net_sales');
        $totalQuantity = (clone $this->query)->sum('quantity_sold');

        return $this->query->selectRaw('
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
        ->map(fn($row) => [
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
        ]);
    }

    public function getCategoryPerformance(): \Illuminate\Support\Collection
    {
        return $this->query->selectRaw('
            category_id,
            SUM(quantity_sold) as total_quantity,
            SUM(net_sales) as total_sales,
            SUM(cost_total) as total_cost,
            SUM(profit_total) as total_profit,
            AVG(margin_percent) as avg_margin,
            SUM(order_count) as total_orders
        ')
        ->groupBy('category_id')
        ->get()
        ->map(fn($row) => [
            'category_id' => $row->category_id,
            'total_quantity' => (int) $row->total_quantity,
            'total_sales' => (float) $row->total_sales,
            'total_cost' => (float) $row->total_cost,
            'total_profit' => (float) $row->total_profit,
            'avg_margin' => (float) $row->avg_margin,
            'total_orders' => (int) $row->total_orders,
        ]);
    }

    public function getTopSellingProducts(int $limit = 10): \Illuminate\Support\Collection
    {
        return $this->groupByProduct()
            ->orderBySales('desc')
            ->query
            ->limit($limit)
            ->get()
            ->map(fn($row) => [
                'product_id' => $row->product_id,
                'category_id' => $row->category_id,
                'total_quantity' => (int) $row->quantity_sold,
                'total_sales' => (float) $row->net_sales,
                'total_profit' => (float) $row->profit_total,
            ]);
    }

    public function getDeadItems(float $salesThreshold = 0.05, float $quantityThreshold = 0.05): \Illuminate\Support\Collection
    {
        $totalSales = (clone $this->query)->sum('net_sales');
        $totalQuantity = (clone $this->query)->sum('quantity_sold');

        return $this->getProductPerformance()
            ->filter(fn($item) => 
                $item['sales_contribution'] <= ($salesThreshold * 100) &&
                $item['quantity_contribution'] <= ($quantityThreshold * 100)
            )
            ->values();
    }

    public function getQuery(): Builder
    {
        return $this->query;
    }
}
