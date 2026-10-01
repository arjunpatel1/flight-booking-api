<?php

namespace Modules\Inventory\Services\InventoryAnalytics;

use DB;
use Illuminate\Support\Carbon;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Models\Ingredient;
use Modules\Inventory\Models\Purchase;
use Modules\Inventory\Models\PurchaseItem;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Supplier;
use Modules\Support\Enums\DateTimeFormat;

class InventoryAnalyticsService implements InventoryAnalyticsServiceInterface
{
    /** @inheritDoc */
    public function topSuppliers(?string $from, ?string $to, ?int $branchId): array
    {
        $user = auth()->user();
        $isBranchUser = $user->assignedToBranch();
        $branch = $isBranchUser ? Branch::find($user->branch_id) : null;

        if ($isBranchUser) {
            $branchId = $user->branch_id;
        }

        $query = Supplier::query()
            ->select('suppliers.name')
            ->join('purchases', 'suppliers.id', '=', 'purchases.supplier_id')
            ->when($branchId, fn($q) => $q->where('purchases.branch_id', $branchId))
            ->when($from, fn($q) => $q->whereDate('purchases.created_at', '>=', $from))
            ->when($to, fn($q) => $q->whereDate('purchases.created_at', '<=', $to))
            ->groupBy('suppliers.name');

        if ($isBranchUser) {
            $query->selectRaw('SUM(purchases.total) as total_amount');
        } else {
            $query->selectRaw('SUM(purchases.total * purchases.currency_rate) as total_amount');
        }

        $data = $query
            ->orderByDesc('total_amount')
            ->limit(10)
            ->get();

        $colors = [
            'rgba(45, 156, 219, 0.6)', 'rgba(39, 174, 96, 0.6)',
            'rgba(243, 156, 18, 0.6)', 'rgba(231, 76, 60, 0.6)',
            'rgba(142, 68, 173, 0.6)', 'rgba(26, 188, 156, 0.6)',
            'rgba(241, 196, 15, 0.6)', 'rgba(149, 165, 166, 0.6)',
            'rgba(230, 126, 34, 0.6)', 'rgba(52, 73, 94, 0.6)',
        ];

        return [
            'currency' => $branch?->currency ?? setting('default_currency'),
            'labels' => $data->pluck('name'),
            'datasets' => [
                [
                    'label' => __("inventory::inventories.analytics.top_suppliers"),
                    'data' => $data->pluck('total_amount'),
                    'backgroundColor' => $data->keys()->map(fn($i) => $colors[$i % count($colors)]),
                ]
            ]
        ];
    }

    /** @inheritDoc */
    public function ingredientPurchases(?string $from, ?string $to, ?int $branchId): array
    {
        $user = auth()->user();

        if ($user->assignedToBranch()) {
            $branchId = $user->branch_id;
        }

        $data = PurchaseItem::query()
            ->select('ingredient_id')
            ->selectRaw('SUM(purchase_items.quantity) as total_quantity')
            ->join('ingredients', 'ingredients.id', '=', 'purchase_items.ingredient_id')
            ->join('units', 'units.id', '=', 'ingredients.unit_id')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->when($branchId, fn($q) => $q->where('purchases.branch_id', $branchId))
            ->when($from, fn($q) => $q->whereDate('purchases.created_at', '>=', $from))
            ->when($to, fn($q) => $q->whereDate('purchases.created_at', '<=', $to))
            ->groupBy('ingredient_id', 'ingredients.name', 'units.symbol')
            ->orderByDesc('total_quantity')
            ->get();

        return $data->map(fn($item) => [
            "id" => $item->ingredient_id,
            "name" => $item->ingredient->name,
            "total_quantity" => (float)$item->total_quantity . " " . ucfirst($item->ingredient->unit->symbol),
        ])->toArray();
    }

    /** @inheritDoc */
    public function stockMovementSummary(?string $from, ?string $to, ?int $branchId): array
    {
        $user = auth()->user();
        if ($user->assignedToBranch()) {
            $branchId = $user->branch_id;
        }

        $data = StockMovement::query()
            ->select('type')
            ->selectRaw('SUM(quantity) as total')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when($from, fn($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn($q) => $q->whereDate('created_at', '<=', $to))
            ->groupBy('type')
            ->get();

        $colors = [
            'in' => 'rgba(46, 204, 113, 0.6)',
            'out' => 'rgba(255, 99, 132, 0.6)',
            'spoil' => 'rgba(241, 196, 15, 0.6)',
            'adjust_add' => 'rgba(52, 152, 219, 0.6)',
            'adjust_subtract' => 'rgba(155, 89, 182, 0.6)',
            'transfer_in' => 'rgba(26, 188, 156, 0.6)',
            'transfer_out' => 'rgba(52, 73, 94, 0.6)',
            'return_supplier' => 'rgba(230, 126, 34, 0.6)',
            'sample' => 'rgba(127, 140, 141, 0.6)',
            'waste' => 'rgba(255, 159, 64, 0.6)',
        ];

        return [
            'labels' => $data->map(fn($item) => $item->type->trans()),
            'datasets' => [
                [
                    'label' => __("inventory::inventories.analytics.stock_movements"),
                    'data' => $data->pluck('total'),
                    'backgroundColor' => $data->pluck('type')->map(fn($type) => $colors[$type->value] ?? 'rgba(189, 195, 199, 0.6)'),
                ]
            ]
        ];
    }

    /** @inheritDoc */
    public function wastageAndSpoilage(?string $from, ?string $to, ?int $branchId): array
    {
        $user = auth()->user();
        $isBranchUser = $user->assignedToBranch();

        $data = StockMovement::query()
            ->select('type')
            ->selectRaw('SUM(quantity) as total')
            ->whereIn('type', ['waste', 'spoil'])
            ->when(!$isBranchUser && $branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when($from, fn($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn($q) => $q->whereDate('created_at', '<=', $to))
            ->groupBy('type')
            ->get();

        return [
            'labels' => $data->map(fn($item) => $item->type->trans()),
            'datasets' => [
                [
                    'label' => __("inventory::inventories.analytics.wastage_and_spoilage"),
                    'data' => $data->pluck('total'),
                    'backgroundColor' => [
                        'rgba(241, 196, 15, 0.6)',
                        'rgba(255, 159, 64, 0.6)',
                    ],
                ]
            ]
        ];
    }

    /** @inheritDoc */
    public function purchaseStatusSummary(?string $from, ?string $to, ?int $branchId): array
    {
        $user = auth()->user();

        if ($user->assignedToBranch()) {
            $branchId = $user->branch_id;
        }

        $data = Purchase::query()
            ->select('status')
            ->selectRaw('COUNT(*) as count')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when($from, fn($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn($q) => $q->whereDate('created_at', '<=', $to))
            ->groupBy('status')
            ->get();

        $colors = [
            'draft' => 'rgba(127, 140, 141, 0.6)',
            'pending' => 'rgba(52, 152, 219, 0.6)',
            'partially_received' => 'rgba(255, 159, 64, 0.6)',
            'received' => 'rgba(46, 204, 113, 0.6)',
            'cancelled' => 'rgba(231, 76, 60, 0.6)',
        ];

        return [
            'labels' => $data->map(fn($item) => $item->status->trans()),
            'datasets' => [
                [
                    'label' => __("inventory::inventories.analytics.purchase_status"),
                    'data' => $data->pluck('count'),
                    'backgroundColor' => $data->pluck('status')->map(fn($status) => $colors[$status->value] ?? 'rgba(189, 195, 199, 0.6)')
                ]
            ]
        ];
    }

    /** @inheritDoc */
    public function getMetaData(): array
    {
        $user = auth()->user();
        $isBranchUser = $user->assignedToBranch();

        $data = [];
        if (!$isBranchUser) {
            $data["branches"] = Branch::list();
        }

        return $data;
    }

    /** @inheritDoc */
    public function lowStockIngredients(?int $branchId = null): array
    {
        $user = auth()->user();
        if ($user->assignedToBranch()) {
            $branchId = $user->branch_id;
        }

        $ingredients = Ingredient::query()
            ->select('id', 'name', 'unit_id', 'current_stock', 'alert_quantity')
            ->with('unit')
            ->whereNotNull('alert_quantity')
            ->whereColumn('current_stock', '<=', 'alert_quantity')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->limit(10)->get();

        return $ingredients->map(fn($ingredient) => [
            'id' => $ingredient->id,
            'name' => $ingredient->name,
            'current_stock' => $ingredient->current_stock . ' ' . $ingredient->unit->symbol,
            'alert_quantity' => $ingredient->alert_quantity . ' ' . $ingredient->unit->symbol,
        ])->toArray();
    }

    /** @inheritDoc */
    public function stockValuation(?int $branchId = null): array
    {
        $user = auth()->user();
        if ($user->assignedToBranch()) {
            $branchId = $user->branch_id;
        }

        $branch = $branchId ? Branch::query()->find($branchId) : null;

        $baseQuery = Ingredient::query()
            ->select([
                'id',
                'name',
                'branch_id',
                'unit_id',
                'current_stock',
                'alert_quantity',
                'cost_per_unit',
            ])
            ->with(['branch:id,name,currency', 'unit:id,symbol'])
            ->when($branchId, fn($query) => $query->where('branch_id', $branchId));

        // Money is stored in minor units but exposed by the model as a Money
        // value. Computing the total in SQL mixed those representations and
        // produced zero/incorrect dashboard totals. Calculate with the same
        // model contract used by the detail rows so both always reconcile.
        $allIngredients = $baseQuery->get();
        $valuedIngredients = $allIngredients->map(function (Ingredient $ingredient) {
            $ingredient->setAttribute(
                'calculated_stock_value',
                round((float) $ingredient->current_stock * ($ingredient->cost_per_unit?->amount() ?? 0), 4)
            );

            return $ingredient;
        });
        $ingredients = $valuedIngredients
            ->sortByDesc(fn(Ingredient $ingredient) => (float) $ingredient->getAttribute('calculated_stock_value'))
            ->take(25)
            ->values();

        return [
            'currency' => $branch?->currency ?? setting('default_currency'),
            'total_value' => round((float) $valuedIngredients->sum('calculated_stock_value'), 4),
            'ingredient_count' => $valuedIngredients->count(),
            'low_stock_count' => $valuedIngredients->filter(fn(Ingredient $ingredient) =>
                !is_null($ingredient->alert_quantity) && $ingredient->current_stock <= $ingredient->alert_quantity
            )->count(),
            'negative_stock_count' => $valuedIngredients->filter(fn(Ingredient $ingredient) =>
                (float) $ingredient->current_stock < 0
            )->count(),
            'items' => $ingredients->map(fn(Ingredient $ingredient) => [
                'id' => $ingredient->id,
                'name' => $ingredient->name,
                'branch' => $ingredient->branch?->name,
                'current_stock' => (float) $ingredient->current_stock,
                'alert_quantity' => (float) $ingredient->alert_quantity,
                'unit' => $ingredient->unit?->symbol ?? '',
                'unit_cost' => $ingredient->cost_per_unit?->amount() ?? 0,
                'stock_value' => (float) $ingredient->getAttribute('calculated_stock_value'),
                'is_low_stock' => !is_null($ingredient->alert_quantity)
                    && $ingredient->current_stock <= $ingredient->alert_quantity,
            ])->toArray(),
        ];
    }

    /** @inheritDoc */
    public function reorderSuggestions(?int $branchId = null, int $lookbackDays = 30, int $targetDays = 7): array
    {
        $user = auth()->user();
        if ($user->assignedToBranch()) {
            $branchId = $user->branch_id;
        }

        $lookbackDays = max(1, min($lookbackDays, 120));
        $targetDays = max(1, min($targetDays, 60));
        $from = now()->subDays($lookbackDays)->startOfDay();

        $usageSubquery = StockMovement::query()
            ->select('ingredient_id')
            ->selectRaw('SUM(quantity) as consumed_quantity')
            ->whereIn('type', collect(StockMovementType::cases())
                ->filter(fn (StockMovementType $type) => $type->isOutgoing())
                ->map(fn (StockMovementType $type) => $type->value)
                ->all())
            ->where('created_at', '>=', $from)
            ->when($branchId, fn($query) => $query->where('branch_id', $branchId))
            ->groupBy('ingredient_id');

        $ingredients = Ingredient::query()
            ->select([
                'ingredients.id',
                'ingredients.name',
                'ingredients.branch_id',
                'ingredients.unit_id',
                'ingredients.current_stock',
                'ingredients.alert_quantity',
                'ingredients.cost_per_unit',
            ])
            ->selectRaw('COALESCE(usage.consumed_quantity, 0) as consumed_quantity')
            ->with(['branch:id,name,currency', 'unit:id,symbol'])
            ->leftJoinSub($usageSubquery, 'usage', 'usage.ingredient_id', '=', 'ingredients.id')
            ->when($branchId, fn($query) => $query->where('ingredients.branch_id', $branchId))
            ->orderByRaw('CASE WHEN ingredients.alert_quantity IS NOT NULL AND ingredients.current_stock <= ingredients.alert_quantity THEN 0 ELSE 1 END')
            ->orderByDesc('consumed_quantity')
            ->limit(25)
            ->get();

        $currency = $branchId
            ? Branch::query()->find($branchId)?->currency
            : setting('default_currency');

        $items = $ingredients->map(function (Ingredient $ingredient) use ($lookbackDays, $targetDays) {
            $consumedQuantity = (float) $ingredient->getAttribute('consumed_quantity');
            $avgDailyUsage = $consumedQuantity > 0 ? $consumedQuantity / $lookbackDays : 0;
            $targetStock = $avgDailyUsage * $targetDays;
            $minimumStock = max((float) ($ingredient->alert_quantity ?? 0), $targetStock);
            $suggestedQuantity = max(0, $minimumStock - (float) $ingredient->current_stock);
            $daysRemaining = $avgDailyUsage > 0
                ? (float) $ingredient->current_stock / $avgDailyUsage
                : null;
            $unitCost = $ingredient->cost_per_unit?->amount() ?? 0;

            return [
                'id' => $ingredient->id,
                'name' => $ingredient->name,
                'branch' => $ingredient->branch?->name,
                'current_stock' => round((float) $ingredient->current_stock, 4),
                'alert_quantity' => is_null($ingredient->alert_quantity) ? null : round((float) $ingredient->alert_quantity, 4),
                'consumed_quantity' => round($consumedQuantity, 4),
                'avg_daily_usage' => round($avgDailyUsage, 4),
                'days_remaining' => is_null($daysRemaining) ? null : round($daysRemaining, 1),
                'suggested_quantity' => round($suggestedQuantity, 4),
                'estimated_cost' => round($suggestedQuantity * $unitCost, 4),
                'unit' => $ingredient->unit?->symbol ?? '',
                'priority' => $ingredient->alert_quantity !== null && $ingredient->current_stock <= $ingredient->alert_quantity
                    ? 'urgent'
                    : ($suggestedQuantity > 0 ? 'soon' : 'monitor'),
            ];
        });

        return [
            'currency' => $currency,
            'lookback_days' => $lookbackDays,
            'target_days' => $targetDays,
            'suggestion_count' => $items->count(),
            'estimated_cost' => round($items->sum('estimated_cost'), 4),
            'items' => $items->toArray(),
        ];
    }

    /** @inheritDoc */
    public function fastMovingIngredients(?string $from = null, ?string $to = null, ?int $branchId = null): array
    {
        $user = auth()->user();

        if ($user->assignedToBranch()) {
            $branchId = $user->branch_id;
        }

        $to = $to ?: now()->toDateString();
        $from = $from ?: now()->subDays(30)->toDateString();

        $topIngredients = StockMovement::query()
            ->select('stock_movements.ingredient_id')
            ->selectRaw('SUM(stock_movements.quantity) as total_quantity')
            ->where('stock_movements.type', StockMovementType::Out->value)
            ->when($branchId, fn($q) => $q->where('stock_movements.branch_id', $branchId))
            ->whereBetween(DB::raw('DATE(stock_movements.created_at)'), [$from, $to])
            ->groupBy('stock_movements.ingredient_id')
            ->orderByDesc('total_quantity')
            ->limit(10)
            ->pluck('ingredient_id');

        $data = StockMovement::query()
            ->select('stock_movements.ingredient_id')
            ->selectRaw('DATE(stock_movements.created_at) as date')
            ->addSelect('ingredients.name')
            ->selectRaw('SUM(stock_movements.quantity) as total')
            ->join('ingredients', 'ingredients.id', '=', 'stock_movements.ingredient_id')
            ->where('stock_movements.type', StockMovementType::Out->value)
            ->whereIn('stock_movements.ingredient_id', $topIngredients)
            ->when($branchId, fn($q) => $q->where('stock_movements.branch_id', $branchId))
            ->whereBetween(DB::raw('DATE(stock_movements.created_at)'), [$from, $to])
            ->groupBy('date', 'stock_movements.ingredient_id', 'ingredients.name')
            ->orderBy('date')
            ->get();

        $grouped = $data->groupBy('name');

        $dates = collect();
        $labels = collect();
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);
        while ($start <= $end) {
            $dates->push($start->toDateString());
            $labels->push(dateTimeFormat($start, DateTimeFormat::Date));
            $start->addDay();
        }

        $colors = [
            'rgba(45, 156, 219, 0.6)',
            'rgba(39, 174, 96, 0.6)',
            'rgba(243, 156, 18, 0.6)',
            'rgba(231, 76, 60, 0.6)',
            'rgba(142, 68, 173, 0.6)',
            'rgba(26, 188, 156, 0.6)',
            'rgba(241, 196, 15, 0.6)',
            'rgba(149, 165, 166, 0.6)',
            'rgba(230, 126, 34, 0.6)',
            'rgba(52, 73, 94, 0.6)',
        ];

        $index = 0;

        $datasets = $grouped->map(function ($entries, $name) use ($dates, $colors, &$index) {
            $color = $colors[$index % count($colors)];
            $daily = $entries->keyBy('date');

            $dataset = [
                'label' => $name,
                'fill' => false,
                // Query results are keyed as Y-m-d. The former implementation
                // looked them up using localized display labels, making every
                // plotted quantity zero even when movements existed.
                'data' => $dates->map(fn($date) => (float)($daily[$date]->total ?? 0)),
                'backgroundColor' => $color,
                'borderColor' => $color,
                "borderWidth" => 2,
                "tension" => 0.3,
            ];

            $index++;
            return $dataset;
        })->values();

        return [
            'labels' => $labels,
            'datasets' => $datasets,
        ];
    }

    /** @inheritDoc */
    public function mostWastedIngredients(?string $from = null, ?string $to = null, ?int $branchId = null): array
    {
        $user = auth()->user();
        if ($user->assignedToBranch()) {
            $branchId = $user->branch_id;
        }

        $data = StockMovement::query()
            ->select('ingredients.id', 'units.symbol as symbol', 'ingredients.name')
            ->selectRaw('SUM(stock_movements.quantity) as total_wasted')
            ->join('ingredients', 'ingredients.id', '=', 'stock_movements.ingredient_id')
            ->join('units', 'units.id', '=', 'ingredients.unit_id')
            ->whereIn('stock_movements.type', [
                StockMovementType::Waste->value,
                StockMovementType::Spoil->value
            ])
            ->when($branchId, fn($q) => $q->where('stock_movements.branch_id', $branchId))
            ->when($from, fn($q) => $q->whereDate('stock_movements.created_at', '>=', $from))
            ->when($to, fn($q) => $q->whereDate('stock_movements.created_at', '<=', $to))
            ->groupBy('ingredients.id', 'ingredients.name', 'units.symbol')
            ->orderByDesc('total_wasted')
            ->limit(5)
            ->get();

        $colors = [
            'rgba(231, 76, 60, 0.6)',
            'rgba(243, 156, 18, 0.6)',
            'rgba(192, 57, 43, 0.6)',
            'rgba(211, 84, 0, 0.6)',
            'rgba(127, 140, 141, 0.6)',
        ];

        return [
            'labels' => $data->pluck('name'),
            'unitSymbols' => $data->pluck('symbol')->map(fn($symbol) => ucfirst($symbol)),
            'datasets' => [
                [
                    'label' => __("inventory::inventories.analytics.most_wasted_ingredients"),
                    'data' => $data->pluck('total_wasted'),
                    'backgroundColor' => $data->keys()->map(fn($i) => $colors[$i % count($colors)]),
                ],
            ],
        ];
    }
}
