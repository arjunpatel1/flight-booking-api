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

class LowStockAlertsReport extends Report
{
    /** @inheritDoc */
    public function key(): string
    {
        return "low_stock_alerts";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "ingredient_name",
            "current_stock",
            "alert_quantity",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "unit_id",
            "current_stock",
            "name",
            "alert_quantity"
        ];
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Ingredient::class;
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            'ingredient_name' => $model->name,
            'current_stock' => $model->current_stock . " " . strtoupper($model->unit->symbol),
            'alert_quantity' => $model->alert_quantity . " " . strtoupper($model->unit->symbol),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->whereColumn('current_stock', '<', 'alert_quantity')
                ->orderBy('current_stock')
        ];
    }

    /** @inheritDoc */
    public function globalFilters(): array
    {
        $branchFilter = GlobalStructureFilters::branch();

        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
        ];
    }

    /** @inheritDoc */
    public function hasSearch(): bool
    {
        return true;
    }
}
