<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Enums\HolderType;
use Modules\Flight\Models\FlightInventory;
use Modules\Flight\Models\FlightInventoryBalance;
use Modules\Flight\Models\FlightInventoryTransaction;
use Modules\Support\ApiResponse;

/**
 * Phase 4: Inventory Ledger Controller
 *
 * Read-only endpoints for the audit trail and balance summary.
 */
class FlightInventoryLedgerController extends Controller
{
    /**
     * Global inventory balance summary (all holders, all tiers).
     *
     * GET /flight/inventory-ledger/balances
     */
    public function balances(Request $request): JsonResponse
    {
        $query = FlightInventoryBalance::query()
            ->with(['flightInventory.airline', 'flightInventory.originAirport', 'flightInventory.destinationAirport'])
            ->orderBy('holder_type')
            ->orderBy('flight_inventory_id');

        if ($inventoryId = $request->query('flight_inventory_id')) {
            $query->where('flight_inventory_id', $inventoryId);
        }

        if ($holderType = $request->query('holder_type')) {
            $query->where('holder_type', $holderType);
        }

        if ($holderId = $request->query('holder_id')) {
            $query->where('holder_id', $holderId);
        }

        if ($cabin = $request->query('cabin_class')) {
            $query->where('cabin_class', strtoupper($cabin));
        }

        $perPage = $request->integer('per_page', 30);

        return ApiResponse::pagination($query->paginate($perPage));
    }

    /**
     * Ledger transactions for a specific flight inventory.
     *
     * GET /flight/inventory-ledger/{inventoryId}/transactions
     */
    public function transactions(Request $request, int $inventoryId): JsonResponse
    {
        $inventory = FlightInventory::findOrFail($inventoryId);

        $query = FlightInventoryTransaction::where('flight_inventory_id', $inventory->id)
            ->orderByDesc('created_at');

        if ($type = $request->query('transaction_type')) {
            $query->where('transaction_type', strtoupper($type));
        }

        if ($holderType = $request->query('holder_type')) {
            $query->forHolder($holderType, $request->query('holder_id'));
        }

        if ($from = $request->query('date_from')) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = $request->query('date_to')) {
            $query->where('created_at', '<=', $to);
        }

        $perPage = $request->integer('per_page', 50);

        return ApiResponse::pagination($query->paginate($perPage));
    }

    /**
     * Summary statistics for a flight inventory.
     *
     * GET /flight/inventory-ledger/{inventoryId}/summary
     */
    public function summary(int $inventoryId): JsonResponse
    {
        $inventory = FlightInventory::with([
            'airline', 'originAirport', 'destinationAirport',
        ])->findOrFail($inventoryId);

        $balances = FlightInventoryBalance::where('flight_inventory_id', $inventoryId)
            ->get()
            ->groupBy('cabin_class')
            ->map(function ($group) {
                return [
                    'by_tier' => $group->groupBy('holder_type')->map(function ($rows) {
                        return $rows->map(fn ($b) => [
                            'holder_id'       => $b->holder_id,
                            'total_allocated' => $b->total_allocated,
                            'available'       => $b->available,
                            'reserved'        => $b->reserved,
                            'booked'          => $b->booked,
                            'frozen'          => $b->frozen,
                            'allocation_mode' => $b->allocation_mode,
                            'utilization_pct' => $b->utilization_rate,
                        ]);
                    }),
                    'totals'  => [
                        'total_allocated' => $group->sum('total_allocated'),
                        'available'       => $group->sum('available'),
                        'reserved'        => $group->sum('reserved'),
                        'booked'          => $group->sum('booked'),
                        'frozen'          => $group->sum('frozen'),
                    ],
                ];
            });

        $txnCounts = FlightInventoryTransaction::where('flight_inventory_id', $inventoryId)
            ->selectRaw('transaction_type, COUNT(*) as count, SUM(ABS(seats_delta)) as total_seats')
            ->groupBy('transaction_type')
            ->get()
            ->keyBy('transaction_type');

        return ApiResponse::success([
            'inventory'         => $inventory,
            'balance_by_cabin'  => $balances,
            'transaction_stats' => $txnCounts,
        ]);
    }

    /**
     * Tenant-scoped inventory balance view.
     *
     * GET /flight/inventory-ledger/tenant-view
     */
    public function tenantView(Request $request): JsonResponse
    {
        $query = FlightInventoryBalance::query()
            ->with(['flightInventory.airline', 'flightInventory.originAirport', 'flightInventory.destinationAirport'])
            ->where('holder_type', HolderType::TENANT->value)
            ->active();

        if ($cabin = $request->query('cabin_class')) {
            $query->where('cabin_class', strtoupper($cabin));
        }

        $perPage = $request->integer('per_page', 20);

        return ApiResponse::pagination($query->paginate($perPage));
    }
}
