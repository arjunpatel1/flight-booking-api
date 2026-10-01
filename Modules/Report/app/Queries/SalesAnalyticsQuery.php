<?php

namespace Modules\Report\Queries;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\Report\Models\FactSalesDaily;

class SalesAnalyticsQuery
{
    private Builder $query;
    private array $filters = [];

    public function __construct()
    {
        $this->query = FactSalesDaily::query();
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

    public function forBranches(array $branchIds): self
    {
        $this->query->whereIn('branch_id', $branchIds);

        return $this;
    }

    public function groupByBranch(): self
    {
        $this->query->groupBy('branch_id');

        return $this;
    }

    public function groupByDate(): self
    {
        $this->query->groupBy('business_date');

        return $this;
    }

    public function orderByDate(string $direction = 'asc'): self
    {
        $this->query->orderBy('business_date', $direction);

        return $this;
    }

    public function orderBySales(string $direction = 'desc'): self
    {
        $this->query->orderBy('net_sales', $direction);

        return $this;
    }

    public function withBranch(): self
    {
        $this->query->with('branch');

        return $this;
    }

    public function getSummary(): array
    {
        $result = $this->query->selectRaw('
            COUNT(*) as total_records,
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

        return [
            'total_records' => (int) ($result->total_records ?? 0),
            'total_orders' => (int) ($result->total_orders ?? 0),
            'net_sales' => (float) ($result->net_sales ?? 0),
            'gross_sales' => (float) ($result->gross_sales ?? 0),
            'profit_total' => (float) ($result->profit_total ?? 0),
            'discount_total' => (float) ($result->discount_total ?? 0),
            'tax_total' => (float) ($result->tax_total ?? 0),
            'avg_order_value' => (float) ($result->avg_order_value ?? 0),
            'unique_customers' => (int) ($result->unique_customers ?? 0),
            'new_customers' => (int) ($result->new_customers ?? 0),
        ];
    }

    public function getGroupedByBranch(): \Illuminate\Support\Collection
    {
        return $this->query->selectRaw('
            branch_id,
            MAX(branches.name) as branch_name,
            SUM(total_orders) as total_orders,
            SUM(net_sales) as net_sales,
            SUM(gross_sales) as gross_sales,
            SUM(profit_total) as profit_total,
            AVG(average_order_value) as avg_order_value,
            SUM(unique_customers) as unique_customers
        ')
        ->leftJoin('branches', 'branches.id', '=', 'fact_sales_daily.branch_id')
        ->groupBy('branch_id')
        ->get()
        ->map(fn($row) => [
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
        ]);
    }

    public function getGroupedByDate(): \Illuminate\Support\Collection
    {
        return $this->query->selectRaw('
            business_date,
            SUM(total_orders) as total_orders,
            SUM(net_sales) as net_sales,
            SUM(gross_sales) as gross_sales,
            SUM(profit_total) as profit_total
        ')
        ->groupBy('business_date')
        ->orderBy('business_date')
        ->get()
        ->map(fn($row) => [
            'date' => $row->business_date,
            'total_orders' => (int) $row->total_orders,
            'net_sales' => (float) $row->net_sales,
            'gross_sales' => (float) $row->gross_sales,
            'profit_total' => (float) $row->profit_total,
        ]);
    }

    public function getTrendData(int $days = 30): \Illuminate\Support\Collection
    {
        $startDate = now()->subDays($days)->toDateString();
        $endDate = now()->toDateString();

        return $this->forDateRange($startDate, $endDate)
            ->groupByDate()
            ->orderByDate()
            ->getGroupedByDate();
    }

    public function getTopPerformingBranches(int $limit = 10): \Illuminate\Support\Collection
    {
        return $this->groupByBranch()
            ->orderBySales('desc')
            ->withBranch()
            ->query
            ->limit($limit)
            ->get()
            ->map(fn($row) => [
                'branch_id' => $row->branch_id,
                'branch_name' => $row->branch?->name ?? 'Unknown',
                'net_sales' => (float) $row->net_sales,
                'total_orders' => (int) $row->total_orders,
            ]);
    }

    public function getQuery(): Builder
    {
        return $this->query;
    }
}
