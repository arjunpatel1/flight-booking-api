<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Inventory\Models\Ingredient;
use Modules\Report\Report;
use Modules\Support\GlobalStructureFilters;
use Modules\Support\Money;

class StockValuationReport extends Report
{
    public function key(): string
    {
        return 'stock_valuation';
    }

    public function attributes(): Collection
    {
        return collect([
            'ingredient_name',
            'current_stock',
            'unit_cost',
            'stock_value',
            'alert_quantity',
            'stock_status',
        ]);
    }

    public function columns(): array
    {
        return [
            'id',
            'unit_id',
            'name',
            'current_stock',
            'cost_per_unit',
            'alert_quantity',
        ];
    }

    public function model(): string
    {
        return Ingredient::class;
    }

    public function resource(Model $model): array
    {
        $currentStock = (float) $model->current_stock;
        $alertQuantity = (float) $model->alert_quantity;
        $unitSymbol = strtoupper($model->unit?->symbol ?? '');

        return [
            'ingredient_name' => $model->name,
            'current_stock' => "{$currentStock} {$unitSymbol}",
            'unit_cost' => new Money((float) $model->getRawOriginal('cost_per_unit'), $this->currency),
            'stock_value' => new Money($currentStock * (float) $model->getRawOriginal('cost_per_unit'), $this->currency),
            'alert_quantity' => "{$alertQuantity} {$unitSymbol}",
            'stock_status' => $currentStock <= 0
                ? __('report::reports.stock_status.out_of_stock')
                : ($currentStock <= $alertQuantity
                    ? __('report::reports.stock_status.low_stock')
                    : __('report::reports.stock_status.healthy')),
        ];
    }

    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->with('unit:id,symbol')
                ->orderBy('name'),
        ];
    }

    public function globalFilters(): array
    {
        $branchFilter = GlobalStructureFilters::branch();

        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
        ];
    }

    public function hasSearch(): bool
    {
        return true;
    }
}
