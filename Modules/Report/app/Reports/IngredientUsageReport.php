<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Models\StockMovement;
use Modules\Order\Models\Order;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;

class IngredientUsageReport extends Report
{
    public function key(): string
    {
        return 'ingredient_usage';
    }

    public function attributes(): Collection
    {
        return collect([
            "date",
            'ingredient_name',
            'total_used',
        ]);
    }

    public function columns(): array
    {
        return [
            'stock_movements.ingredient_id',
            'MIN(stock_movements.created_at) as start_date',
            'MAX(stock_movements.created_at) as end_date',
            'SUM(stock_movements.quantity) as total_used',
        ];
    }

    public function model(): string
    {
        return StockMovement::class;
    }

    public function resource(Model $model): array
    {
        return [
            "date" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            'ingredient_name' => $model->ingredient->name,
            'total_used' => ((float)$model->total_used) . " " . strtoupper($model->ingredient->unit->symbol),
        ];
    }

    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->where('stock_movements.source_type', Order::class)
                ->where('stock_movements.type', StockMovementType::Out)
                ->groupBy('stock_movements.ingredient_id')
        ];
    }

    public function with(): array
    {
        return [
            'ingredient' => fn($q) => $q->select('id', 'name', 'unit_id'),
        ];
    }

    public function hasSearch(): bool
    {
        return true;
    }
}
