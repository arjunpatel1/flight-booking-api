<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Services\Catalog\FlightCatalogTenant;
use Modules\Flight\Enums\AllocationStatus;
use Modules\Flight\Enums\HolderType;
use Modules\Flight\Models\FlightInventory;
use Modules\Flight\Models\FlightInventoryAllocation;
use Modules\Flight\Services\Inventory\FlightInventoryAllocationEngine;
use Modules\Support\ApiResponse;

/**
 * Phase 4: Inventory Allocation Controller
 *
 * Handles seat allocation across the hierarchy:
 *   Central → Tenant → Distributor → Agent → B2C
 */
class FlightInventoryAllocationController extends Controller
{
    public function __construct(
        private readonly FlightInventoryAllocationEngine $engine,
    ) {}

    /**
     * List allocations (filterable by inventory, status, holder).
     */
    public function index(Request $request): JsonResponse
    {
        $query = FlightInventoryAllocation::query()
            ->with([
                'flightInventory.airline',
                'flightInventory.originAirport',
                'flightInventory.destinationAirport',
                'parentAllocation',
            ])
            ->latest('created_at');

        if ($inventoryId = $request->query('flight_inventory_id')) {
            $query->where('flight_inventory_id', $inventoryId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', strtoupper($status));
        }

        if ($fromType = $request->query('from_holder_type')) {
            $query->where('from_holder_type', $fromType);
        }

        if ($toType = $request->query('to_holder_type')) {
            $query->where('to_holder_type', $toType);
        }

        if ($cabin = $request->query('cabin_class')) {
            $query->where('cabin_class', strtoupper($cabin));
        }

        if ($search = $request->query('search')) {
            $query->search($search);
        }

        $perPage = $request->integer('per_page', 20);

        return ApiResponse::pagination($query->paginate($perPage));
    }

    /**
     * Show a single allocation.
     */
    public function show(int $id): JsonResponse
    {
        $allocation = FlightInventoryAllocation::with([
            'flightInventory.airline',
            'flightInventory.originAirport',
            'flightInventory.destinationAirport',
            'parentAllocation',
            'childAllocations',
            'transactions',
        ])->findOrFail($id);

        return ApiResponse::success($allocation);
    }

    /**
     * Create a new allocation request.
     *
     * POST /flight/inventory-allocations
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'flight_inventory_id'   => 'required|integer|exists:flight_inventories,id',
            'cabin_class'           => ['required', FlightCatalogTenant::activeCabinClass()],
            'from_holder_type'      => 'required|in:central,tenant,distributor,agent',
            'from_holder_id'        => 'nullable|integer',
            'to_holder_type'        => 'required|in:tenant,distributor,agent,b2c',
            'to_holder_id'          => 'nullable|integer',
            'parent_allocation_id'  => 'nullable|integer|exists:flight_inventory_allocations,id',
            'seats'                 => 'required|integer|min:1|max:1000',
            'allocation_mode'       => 'nullable|in:fixed,shared_pool,hybrid',
            'floor_seats'           => 'nullable|integer|min:0',
            'expires_at'            => 'nullable|date|after:now',
            'notes'                 => 'nullable|string|max:1000',
        ]);

        $inventory = FlightInventory::findOrFail($data['flight_inventory_id']);

        try {
            $allocation = $this->engine->allocate(
                inventory:           $inventory,
                cabin:               $data['cabin_class'],
                fromType:            HolderType::from($data['from_holder_type']),
                fromId:              $data['from_holder_id'] ?? null,
                toType:              HolderType::from($data['to_holder_type']),
                toId:                $data['to_holder_id'] ?? null,
                seats:               $data['seats'],
                allocationMode:      $data['allocation_mode'] ?? 'fixed',
                floorSeats:          $data['floor_seats'] ?? 0,
                parentAllocationId:  $data['parent_allocation_id'] ?? null,
                notes:               $data['notes'] ?? null,
                expiresAt:           isset($data['expires_at']) ? new \DateTime($data['expires_at']) : null,
                actorId:             auth()->id(),
            );
        } catch (\RuntimeException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        }

        return ApiResponse::created($allocation->load([
            'flightInventory.airline',
            'flightInventory.originAirport',
            'flightInventory.destinationAirport',
        ]));
    }

    /**
     * Approve an allocation (REQUESTED → APPROVED → COMPLETED in one step for auto-approve).
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $allocation = FlightInventoryAllocation::findOrFail($id);

        if (! $allocation->canApprove()) {
            return ApiResponse::errors(
                null,
                "Cannot approve allocation in status: {$allocation->status->label()}.",
                422
            );
        }

        $allocation->update([
            'status'      => AllocationStatus::COMPLETED->value,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'completed_at'=> now(),
            'notes'       => $request->input('notes', $allocation->notes),
        ]);

        return ApiResponse::success($allocation->fresh());
    }

    /**
     * Reject an allocation.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        $allocation = FlightInventoryAllocation::findOrFail($id);

        if (! $allocation->canReject()) {
            return ApiResponse::errors(
                null,
                "Cannot reject allocation in status: {$allocation->status->label()}.",
                422
            );
        }

        $allocation->update([
            'status'           => AllocationStatus::REJECTED->value,
            'rejection_reason' => $data['rejection_reason'],
        ]);

        return ApiResponse::success($allocation->fresh());
    }

    /**
     * Cancel a pending allocation.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $allocation = FlightInventoryAllocation::findOrFail($id);

        if (! $allocation->canCancel()) {
            return ApiResponse::errors(
                null,
                "Cannot cancel allocation in status: {$allocation->status->label()}.",
                422
            );
        }

        $allocation->update([
            'status' => AllocationStatus::CANCELLED->value,
            'notes'  => $request->input('notes', $allocation->notes),
        ]);

        return ApiResponse::success($allocation->fresh());
    }

    /**
     * Reverse a completed allocation (seats returned to source).
     */
    public function reverse(Request $request, int $id): JsonResponse
    {
        $allocation = FlightInventoryAllocation::findOrFail($id);

        if (! $allocation->canReverse()) {
            return ApiResponse::errors(
                null,
                "Cannot reverse allocation in status: {$allocation->status->label()}.",
                422
            );
        }

        $inventory = $allocation->flightInventory;

        $this->engine->returnSeats(
            inventory:  $inventory,
            cabin:      $allocation->cabin_class,
            fromType:   HolderType::from($allocation->to_holder_type->value),
            fromId:     $allocation->to_holder_id,
            toType:     HolderType::from($allocation->from_holder_type->value),
            toId:       $allocation->from_holder_id,
            seats:      $allocation->seats_allocated,
            allocation: $allocation,
            reason:     $request->input('reason', 'Manual reversal'),
            actorId:    auth()->id(),
        );

        return ApiResponse::success($allocation->fresh(['transactions']));
    }
}
