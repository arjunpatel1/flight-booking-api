<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Models\StockMovement;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class WastageCostReport extends Report
{
    public function __construct()
    {
        parent::__construct();

        $this->model()::$defaultDateColumn = 'stock_movements.created_at';
    }

    public function key(): string
    {
        return 'wastage_cost';
    }

    public function attributes(): Collection
    {
        return collect([
            'period',
            'ingredient_name',
            'reason',
            'total_wasted',
            'waste_count',
            'total_cost',
        ]);
    }

    public function columns(): array
    {
        return [
            'stock_movements.ingredient_id',
            "SUBSTRING_INDEX(REPLACE(COALESCE(stock_movements.note, ''), 'Reason: ', ''), '\n', 1) as reason",
            'MIN(stock_movements.created_at) as start_date',
            'MAX(stock_movements.created_at) as end_date',
            'SUM(stock_movements.quantity) as total_wasted',
            'COUNT(stock_movements.id) as waste_count',
            'SUM(stock_movements.quantity * ingredients.cost_per_unit) as total_cost',
        ];
    }

    public function model(): string
    {
        return StockMovement::class;
    }

    public function resource(Model $model): array
    {
        $unitSymbol = strtoupper($model->ingredient?->unit?->symbol ?? '');
        $reason = trim((string) $model->reason);

        return [
            'period' => dateTimeFormat(Carbon::parse($model->start_date), DateTimeFormat::Date) . ' - ' . dateTimeFormat(Carbon::parse($model->end_date), DateTimeFormat::Date),
            'ingredient_name' => $model->ingredient?->name ?? __('report::reports.unassigned'),
            'reason' => $reason === '' ? __('report::reports.unassigned') : $reason,
            'total_wasted' => ((float) $model->total_wasted) . " {$unitSymbol}",
            'waste_count' => (int) $model->waste_count,
            'total_cost' => new Money((float) $model->total_cost, $this->currency),
        ];
    }

    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('ingredients', 'ingredients.id', '=', 'stock_movements.ingredient_id')
                ->where('stock_movements.type', StockMovementType::Waste)
                ->groupBy('stock_movements.ingredient_id', DB::raw("SUBSTRING_INDEX(REPLACE(COALESCE(stock_movements.note, ''), 'Reason: ', ''), '\n', 1)")),
        ];
    }

    public function with(): array
    {
        return [
            'ingredient' => fn($query) => $query->select('id', 'name', 'unit_id')->with('unit:id,symbol'),
        ];
    }

    public function hasSearch(): bool
    {
        return true;
    }
}
