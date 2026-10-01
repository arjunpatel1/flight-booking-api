<?php

namespace Modules\Report\Queries;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\Report\Models\FactInventoryDaily;

class InventoryAnalyticsQuery
{
    private Builder $query;

    public function __construct()
    {
        $this->query = FactInventoryDaily::query();
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

    public function forIngredient(?int $ingredientId): self
    {
        if ($ingredientId) {
            $this->query->where('ingredient_id', $ingredientId);
        }

        return $this;
    }

    public function forWarehouse(?int $warehouseId): self
    {
        if ($warehouseId) {
            $this->query->where('warehouse_id', $warehouseId);
        }

        return $this;
    }

    public function groupByIngredient(): self
    {
        $this->query->groupBy('ingredient_id');

        return $this;
    }

    public function groupByWarehouse(): self
    {
        $this->query->groupBy('warehouse_id');

        return $this;
    }

    public function getSummary(): array
    {
        $result = $this->query->selectRaw('
            SUM(purchased_qty) as total_purchased,
            SUM(consumed_qty) as total_consumed,
            SUM(wasted_qty) as total_wasted,
            SUM(transferred_in_qty) as total_transferred_in,
            SUM(transferred_out_qty) as total_transferred_out,
            SUM(purchase_value) as total_purchase_value,
            SUM(consumed_value) as total_consumed_value,
            SUM(wasted_value) as total_wasted_value
        ')
        ->first();

        $totalPurchased = (float) ($result->total_purchased ?? 0);
        $totalWasted = (float) ($result->total_wasted ?? 0);

        return [
            'total_purchased' => $totalPurchased,
            'total_consumed' => (float) ($result->total_consumed ?? 0),
            'total_wasted' => $totalWasted,
            'total_transferred_in' => (float) ($result->total_transferred_in ?? 0),
            'total_transferred_out' => (float) ($result->total_transferred_out ?? 0),
            'total_purchase_value' => (float) ($result->total_purchase_value ?? 0),
            'total_consumed_value' => (float) ($result->total_consumed_value ?? 0),
            'total_wasted_value' => (float) ($result->total_wasted_value ?? 0),
            'wastage_percentage' => $totalPurchased > 0 
                ? round(($totalWasted / $totalPurchased) * 100, 2) 
                : 0,
        ];
    }

    public function getLowStockItems(float $threshold = 10): \Illuminate\Support\Collection
    {
        return $this->query->selectRaw('
            ingredient_id,
            warehouse_id,
            SUM(closing_stock) as total_closing_stock,
            SUM(purchased_qty) as total_purchased,
            SUM(consumed_qty) as total_consumed
        ')
        ->groupBy('ingredient_id', 'warehouse_id')
        ->havingRaw('SUM(closing_stock) <= ?', [$threshold])
        ->get()
        ->map(fn($row) => [
            'ingredient_id' => $row->ingredient_id,
            'warehouse_id' => $row->warehouse_id,
            'closing_stock' => (float) $row->total_closing_stock,
            'purchased_qty' => (float) $row->total_purchased,
            'consumed_qty' => (float) $row->total_consumed,
        ]);
    }

    public function getHighWastageItems(float $thresholdPercentage = 5): \Illuminate\Support\Collection
    {
        return $this->query->selectRaw('
            ingredient_id,
            warehouse_id,
            SUM(purchased_qty) as total_purchased,
            SUM(wasted_qty) as total_wasted,
            SUM(wasted_value) as total_wasted_value
        ')
        ->groupBy('ingredient_id', 'warehouse_id')
        ->havingRaw('(SUM(wasted_qty) / NULLIF(SUM(purchased_qty), 0)) * 100 >= ?', [$thresholdPercentage])
        ->get()
        ->map(fn($row) => [
            'ingredient_id' => $row->ingredient_id,
            'warehouse_id' => $row->warehouse_id,
            'purchased_qty' => (float) $row->total_purchased,
            'wasted_qty' => (float) $row->total_wasted,
            'wasted_value' => (float) $row->total_wasted_value,
            'wastage_percentage' => $row->total_purchased > 0 
                ? round(($row->total_wasted / $row->total_purchased) * 100, 2) 
                : 0,
        ]);
    }

    public function getIngredientPerformance(): \Illuminate\Support\Collection
    {
        return $this->query->selectRaw('
            ingredient_id,
            SUM(purchased_qty) as total_purchased,
            SUM(consumed_qty) as total_consumed,
            SUM(wasted_qty) as total_wasted,
            SUM(purchase_value) as total_purchase_value,
            SUM(consumed_value) as total_consumed_value,
            SUM(wasted_value) as total_wasted_value
        ')
        ->groupBy('ingredient_id')
        ->get()
        ->map(fn($row) => [
            'ingredient_id' => $row->ingredient_id,
            'total_purchased' => (float) $row->total_purchased,
            'total_consumed' => (float) $row->total_consumed,
            'total_wasted' => (float) $row->total_wasted,
            'total_purchase_value' => (float) $row->total_purchase_value,
            'total_consumed_value' => (float) $row->total_consumed_value,
            'total_wasted_value' => (float) $row->total_wasted_value,
            'consumption_rate' => $row->total_purchased > 0 
                ? round(($row->total_consumed / $row->total_purchased) * 100, 2) 
                : 0,
            'wastage_rate' => $row->total_purchased > 0 
                ? round(($row->total_wasted / $row->total_purchased) * 100, 2) 
                : 0,
        ]);
    }

    public function getAlerts(): array
    {
        $lowStockCount = $this->getLowStockItems()->count();
        $highWastageCount = $this->getHighWastageItems()->count();

        return [
            'low_stock_items' => $lowStockCount,
            'high_wastage_items' => $highWastageCount,
            'total_alerts' => $lowStockCount + $highWastageCount,
        ];
    }

    public function getQuery(): Builder
    {
        return $this->query;
    }
}
