<?php

namespace Modules\Report\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Report\Models\FactKitchenDaily;

class KitchenAnalyticsQuery
{
    protected Builder $query;
    protected ?int $branchId = null;
    protected ?string $startDate = null;
    protected ?string $endDate = null;
    protected ?int $stationId = null;

    public function __construct()
    {
        $this->query = FactKitchenDaily::query();
    }

    public function forBranch(?int $branchId): self
    {
        $this->branchId = $branchId;
        return $this;
    }

    public function forDateRange(?string $startDate, ?string $endDate): self
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        return $this;
    }

    public function forStation(?int $stationId): self
    {
        $this->stationId = $stationId;
        return $this;
    }

    public function getSummary(): array
    {
        $query = $this->query;

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        if ($this->stationId) {
            $query->where('kitchen_station_id', $this->stationId);
        }

        if ($this->startDate && $this->endDate) {
            $query->whereBetween('business_date', [$this->startDate, $this->endDate]);
        }

        $result = $query->select([
            DB::raw('SUM(total_orders) as total_orders'),
            DB::raw('SUM(completed_orders) as completed_orders'),
            DB::raw('SUM(delayed_orders) as delayed_orders'),
            DB::raw('AVG(avg_preparation_time_minutes) as avg_preparation_time_minutes'),
            DB::raw('AVG(avg_kot_delay_minutes) as avg_kot_delay_minutes'),
            DB::raw('MAX(max_preparation_time_minutes) as max_preparation_time_minutes'),
            DB::raw('AVG(orders_per_hour) as orders_per_hour'),
            DB::raw('AVG(on_time_percentage) as on_time_percentage'),
            DB::raw('MAX(currency) as currency'),
        ])->first();

        return [
            'total_orders' => (int) ($result->total_orders ?? 0),
            'completed_orders' => (int) ($result->completed_orders ?? 0),
            'delayed_orders' => (int) ($result->delayed_orders ?? 0),
            'avg_preparation_time_minutes' => (float) ($result->avg_preparation_time_minutes ?? 0),
            'avg_kot_delay_minutes' => (float) ($result->avg_kot_delay_minutes ?? 0),
            'max_preparation_time_minutes' => (int) ($result->max_preparation_time_minutes ?? 0),
            'orders_per_hour' => (float) ($result->orders_per_hour ?? 0),
            'on_time_percentage' => (float) ($result->on_time_percentage ?? 0),
            'currency' => $result->currency ?? 'USD',
        ];
    }

    public function getByStation(): array
    {
        $query = $this->query;

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        if ($this->startDate && $this->endDate) {
            $query->whereBetween('business_date', [$this->startDate, $this->endDate]);
        }

        return $query->select([
            'kitchen_station_id',
            DB::raw('SUM(total_orders) as total_orders'),
            DB::raw('SUM(completed_orders) as completed_orders'),
            DB::raw('SUM(delayed_orders) as delayed_orders'),
            DB::raw('AVG(avg_preparation_time_minutes) as avg_preparation_time_minutes'),
            DB::raw('AVG(avg_kot_delay_minutes) as avg_kot_delay_minutes'),
            DB::raw('AVG(orders_per_hour) as orders_per_hour'),
            DB::raw('AVG(on_time_percentage) as on_time_percentage'),
            DB::raw('MAX(currency) as currency'),
        ])
            ->whereNotNull('kitchen_station_id')
            ->groupBy('kitchen_station_id')
            ->orderByDesc('total_orders')
            ->get()
            ->toArray();
    }

    public function getDailyTrend(): array
    {
        $query = $this->query;

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        if ($this->stationId) {
            $query->where('kitchen_station_id', $this->stationId);
        }

        if ($this->startDate && $this->endDate) {
            $query->whereBetween('business_date', [$this->startDate, $this->endDate]);
        }

        return $query->select([
            'business_date',
            DB::raw('SUM(total_orders) as total_orders'),
            DB::raw('SUM(completed_orders) as completed_orders'),
            DB::raw('SUM(delayed_orders) as delayed_orders'),
            DB::raw('AVG(avg_preparation_time_minutes) as avg_preparation_time_minutes'),
            DB::raw('AVG(avg_kot_delay_minutes) as avg_kot_delay_minutes'),
            DB::raw('AVG(orders_per_hour) as orders_per_hour'),
            DB::raw('MAX(currency) as currency'),
        ])
            ->groupBy('business_date')
            ->orderBy('business_date')
            ->get()
            ->toArray();
    }

    public function getPeakHours(): array
    {
        // This will query the KOT fact table for peak hours
        $query = \Modules\Report\Models\FactKotDaily::query();

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        if ($this->stationId) {
            $query->where('kitchen_station_id', $this->stationId);
        }

        if ($this->startDate && $this->endDate) {
            $query->whereBetween('business_date', [$this->startDate, $this->endDate]);
        }

        return $query->select([
            'hour',
            DB::raw('SUM(total_kots) as total_kots'),
            DB::raw('SUM(total_quantity) as total_quantity'),
            DB::raw('AVG(avg_kot_time_minutes) as avg_kot_time_minutes'),
            DB::raw('AVG(avg_delay_minutes) as avg_delay_minutes'),
            DB::raw('MAX(currency) as currency'),
        ])
            ->whereNotNull('hour')
            ->groupBy('hour')
            ->orderByDesc('total_kots')
            ->limit(10)
            ->get()
            ->toArray();
    }
}
