<?php

namespace Modules\Inventory\Services\WastageTracking;

use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Models\Ingredient;
use Modules\Inventory\Models\StockMovement;
use Throwable;

class WastageTrackingService implements WastageTrackingServiceInterface
{
    /** @inheritDoc */
    public function recordWastage(
        int $ingredientId,
        float $quantity,
        string $reason,
        int $branchId,
        ?int $reportedBy = null,
        ?string $note = null
    ): array {
        try {
            return DB::transaction(function () use ($ingredientId, $quantity, $reason, $branchId, $reportedBy, $note) {
                $ingredient = Ingredient::query()
                    ->where('branch_id', $branchId)
                    ->lockForUpdate()
                    ->find($ingredientId);

                if (!$ingredient) {
                    return [
                        'success' => false,
                        'message' => __('inventory::messages.ingredient_not_found'),
                    ];
                }

                if ((float)$ingredient->current_stock < $quantity) {
                    return [
                        'success' => false,
                        'message' => __('inventory::messages.insufficient_stock_for_wastage', [
                            'available' => round((float)$ingredient->current_stock, 4),
                            'unit' => $ingredient->unit?->symbol ?? '',
                        ]),
                    ];
                }

                $movement = StockMovement::create([
                    'ingredient_id' => $ingredientId,
                    'branch_id' => $branchId,
                    'type' => StockMovementType::Waste,
                    'quantity' => $quantity,
                    'note' => $this->formatWastageNote($reason, $note),
                ]);

                if (!is_null($reportedBy)) {
                    StockMovement::withoutEvents(
                        fn() => $movement->forceFill(['created_by' => $reportedBy])->save()
                    );
                }

                return [
                    'success' => true,
                    'movement_id' => $movement->id,
                    'remaining_stock' => round((float)$ingredient->fresh()->current_stock, 4),
                ];
            });
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /** @inheritDoc */
    public function getWastageReport(
        string $from,
        string $to,
        ?int $branchId = null,
        ?int $ingredientId = null
    ): Collection {
        $query = StockMovement::query()
            ->select([
                'stock_movements.ingredient_id',
                DB::raw('SUM(stock_movements.quantity) as total_wasted'),
                DB::raw('SUM(stock_movements.quantity * ingredients.cost_per_unit) as total_cost'),
                DB::raw('COUNT(*) as waste_count'),
                'stock_movements.note',
            ])
            ->with(['ingredient:id,name,unit_id,cost_per_unit', 'ingredient.unit:id,symbol'])
            ->join('ingredients', 'ingredients.id', '=', 'stock_movements.ingredient_id')
            ->where('stock_movements.type', StockMovementType::Waste)
            ->whereBetween('stock_movements.created_at', [
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            ])
            ->groupBy('stock_movements.ingredient_id', 'stock_movements.note');

        if ($branchId) {
            $query->where('stock_movements.branch_id', $branchId);
        }

        if ($ingredientId) {
            $query->where('stock_movements.ingredient_id', $ingredientId);
        }

        $movements = $query->get();

        return $movements->groupBy('ingredient_id')->map(function ($group) {
            $first = $group->first();
            $ingredient = $first->ingredient;
            $reasonBreakdown = $group
                ->groupBy(fn($item) => $this->extractReason($item->note))
                ->map(fn(Collection $items) => round((float)$items->sum('total_wasted'), 4));

            $totalWasted = $group->sum('total_wasted');
            $totalCost = $group->sum('total_cost');

            return [
                'ingredient_id' => $ingredient->id,
                'ingredient_name' => $ingredient->name,
                'total_wasted' => $totalWasted,
                'unit' => $ingredient->unit?->symbol ?? '',
                'cost' => round($totalCost, 4),
                'waste_count' => $group->sum('waste_count'),
                'reason_breakdown' => $reasonBreakdown,
            ];
        })->values();
    }

    /** @inheritDoc */
    public function getTopWastedIngredients(
        string $from,
        string $to,
        int $limit = 10,
        ?int $branchId = null
    ): Collection {
        $query = StockMovement::query()
            ->select([
                'stock_movements.ingredient_id',
                DB::raw('SUM(stock_movements.quantity) as total_wasted'),
                DB::raw('SUM(stock_movements.quantity * ingredients.cost_per_unit) as total_cost'),
            ])
            ->join('ingredients', 'ingredients.id', '=', 'stock_movements.ingredient_id')
            ->where('stock_movements.type', StockMovementType::Waste)
            ->whereBetween('stock_movements.created_at', [
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            ])
            ->groupBy('stock_movements.ingredient_id')
            ->orderByDesc('total_wasted')
            ->limit($limit);

        if ($branchId) {
            $query->where('stock_movements.branch_id', $branchId);
        }

        $results = $query->get();

        return $results->map(function ($item) {
            $ingredient = Ingredient::with('unit')->find($item->ingredient_id);

            return [
                'ingredient_id' => $item->ingredient_id,
                'name' => $ingredient?->name ?? 'Unknown',
                'total_wasted' => (float) $item->total_wasted,
                'unit' => $ingredient?->unit?->symbol ?? '',
                'cost' => round((float) $item->total_cost, 4),
            ];
        });
    }

    /** @inheritDoc */
    public function getWastageByReason(
        string $from,
        string $to,
        ?int $branchId = null
    ): Collection {
        $query = StockMovement::query()
            ->select([
                DB::raw("SUBSTRING_INDEX(REPLACE(stock_movements.note, 'Reason: ', ''), '\n', 1) as reason"),
                DB::raw('SUM(stock_movements.quantity) as total_quantity'),
                DB::raw('SUM(stock_movements.quantity * ingredients.cost_per_unit) as total_cost'),
            ])
            ->join('ingredients', 'ingredients.id', '=', 'stock_movements.ingredient_id')
            ->where('stock_movements.type', StockMovementType::Waste)
            ->whereBetween('stock_movements.created_at', [
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            ])
            ->groupBy('reason');

        if ($branchId) {
            $query->where('stock_movements.branch_id', $branchId);
        }

        $results = $query->get();
        $totalWasted = $results->sum('total_quantity');

        return $results->map(function ($item) use ($totalWasted) {
            $quantity = (float) $item->total_quantity;

            return [
                'reason' => $item->reason ?: 'unknown',
                'total_quantity' => $quantity,
                'cost' => round((float)$item->total_cost, 4),
                'percentage' => $totalWasted > 0 ? round(($quantity / $totalWasted) * 100, 2) : 0,
            ];
        });
    }

    /** @inheritDoc */
    public function getWastageReasons(): array
    {
        return [
            'expired' => __('inventory::wastage.reasons.expired'),
            'spoiled' => __('inventory::wastage.reasons.spoiled'),
            'over_prep' => __('inventory::wastage.reasons.over_prep'),
            'dropped' => __('inventory::wastage.reasons.dropped'),
            'quality_issue' => __('inventory::wastage.reasons.quality_issue'),
            'incorrect_order' => __('inventory::wastage.reasons.incorrect_order'),
            'temperature_failure' => __('inventory::wastage.reasons.temperature_failure'),
            'pest_damage' => __('inventory::wastage.reasons.pest_damage'),
            'other' => __('inventory::wastage.reasons.other'),
        ];
    }

    /** @inheritDoc */
    public function getWastageTrends(
        string $from,
        string $to,
        string $groupBy = 'day',
        ?int $branchId = null
    ): Collection {
        $dateFormat = match ($groupBy) {
            'week' => '%Y-%u',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };

        $query = StockMovement::query()
            ->select([
                DB::raw("DATE_FORMAT(stock_movements.created_at, '{$dateFormat}') as period"),
                DB::raw('SUM(stock_movements.quantity) as total_wasted'),
                DB::raw('SUM(stock_movements.quantity * ingredients.cost_per_unit) as cost'),
            ])
            ->join('ingredients', 'ingredients.id', '=', 'stock_movements.ingredient_id')
            ->where('stock_movements.type', StockMovementType::Waste)
            ->whereBetween('stock_movements.created_at', [
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            ])
            ->groupBy('period')
            ->orderBy('period');

        if ($branchId) {
            $query->where('stock_movements.branch_id', $branchId);
        }

        return $query->get()->map(fn($item) => [
            'period' => $item->period,
            'total_wasted' => (float) $item->total_wasted,
            'cost' => round((float)$item->cost, 4),
        ]);
    }

    private function formatWastageNote(string $reason, ?string $note = null): string
    {
        $note = trim((string)$note);

        return $note === ''
            ? "Reason: {$reason}"
            : "Reason: {$reason}\n{$note}";
    }

    private function extractReason(?string $note): string
    {
        if (empty($note)) {
            return 'unknown';
        }

        $line = strtok($note, "\n") ?: $note;

        return trim(str_replace('Reason: ', '', $line)) ?: 'unknown';
    }
}
