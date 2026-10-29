<?php

namespace Modules\Flight\Services\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Flight\Enums\AllocationStatus;
use Modules\Flight\Enums\HolderType;
use Modules\Flight\Enums\TransactionType;
use Modules\Flight\Models\FlightInventory;
use Modules\Flight\Models\FlightInventoryAllocation;
use Modules\Flight\Models\FlightInventoryBalance;
use Modules\Flight\Models\FlightInventoryReservation;
use Modules\Flight\Models\FlightInventoryTransaction;
use RuntimeException;

/**
 * Phase 4: Flight Inventory Allocation Engine
 *
 * Central service for all seat-movement operations.
 * All balance mutations happen inside DB::transaction() with pessimistic locks.
 *
 * Hierarchy rule: a child's allocated seats can never exceed its parent's available seats.
 *
 * Methods:
 *   allocate()           â€” Central â†’ Tenant â†’ Distributor â†’ Agent
 *   returnSeats()        â€” Agent â†’ Distributor â†’ Tenant â†’ Central
 *   freeze()             â€” Lock seats (admin hold)
 *   expireAllocations()  â€” Cron: return expired allocations to parent
 *   reserveSeats()       â€” Booking pipeline hold
 *   confirmReservation() â€” Booking confirmed
 *   releaseReservation() â€” Booking abandoned / timeout
 *   getLedger()          â€” Audit trail for a flight inventory
 */
class FlightInventoryAllocationEngine
{
    // â”€â”€ Allocate â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Allocate seats from a parent holder to a child holder.
     *
     * @throws RuntimeException if parent has insufficient available seats
     */
    public function allocate(
        FlightInventory $inventory,
        string $cabin,
        HolderType $fromType,
        ?int $fromId,
        HolderType $toType,
        ?int $toId,
        int $seats,
        string $allocationMode = 'fixed',
        int $floorSeats = 0,
        ?int $parentAllocationId = null,
        ?string $notes = null,
        ?\DateTime $expiresAt = null,
        ?int $actorId = null,
    ): FlightInventoryAllocation {
        // Validate hierarchy
        if (! in_array($toType, $fromType->allowedChildren())) {
            throw new RuntimeException(
                "Cannot allocate from {$fromType->label()} to {$toType->label()}: invalid hierarchy."
            );
        }

        return DB::transaction(function () use (
            $inventory, $cabin, $fromType, $fromId, $toType, $toId,
            $seats, $allocationMode, $floorSeats, $parentAllocationId,
            $notes, $expiresAt, $actorId
        ) {
            // Lock source balance
            $fromBalance = $this->lockBalance($inventory, $cabin, $fromType, $fromId);

            if ($fromBalance->available < $seats) {
                throw new RuntimeException(
                    "Insufficient seats: {$fromType->label()} has {$fromBalance->available} available, requested {$seats}."
                );
            }

            // Create allocation record
            $allocation = FlightInventoryAllocation::create([
                'tenant_id'           => $inventory->tenant_id,
                'flight_inventory_id' => $inventory->id,
                'cabin_class'         => $cabin,
                'from_holder_type'    => $fromType->value,
                'from_holder_id'      => $fromId,
                'to_holder_type'      => $toType->value,
                'to_holder_id'        => $toId,
                'parent_allocation_id'=> $parentAllocationId,
                'seats_requested'     => $seats,
                'seats_allocated'     => $seats,
                'allocation_mode'     => $allocationMode,
                'floor_seats'         => $floorSeats,
                'status'              => AllocationStatus::COMPLETED->value,
                'requested_by'        => $actorId,
                'approved_by'         => $actorId,
                'processed_by'        => $actorId,
                'requested_at'        => now(),
                'approved_at'         => now(),
                'completed_at'        => now(),
                'expires_at'          => $expiresAt,
                'notes'               => $notes,
            ]);

            // Deduct from source
            $fromBefore = $fromBalance->available;
            $fromBalance->decrement('available', $seats);
            $fromBalance->refresh();

            // Credit to destination
            $toBalance = $this->findOrCreateBalance($inventory, $cabin, $toType, $toId, $allocationMode, $floorSeats);
            $toBefore  = $toBalance->available;
            $toBalance->increment('available', $seats);
            $toBalance->increment('total_allocated', $seats);
            $toBalance->refresh();

            // Write ledger
            $this->writeTransaction(
                type: TransactionType::CHANNEL_ALLOCATION,
                inventory: $inventory,
                cabin: $cabin,
                allocationId: $allocation->id,
                fromType: $fromType,
                fromId: $fromId,
                toType: $toType,
                toId: $toId,
                delta: $seats,
                fromBefore: $fromBefore,
                fromAfter: $fromBalance->available,
                toBefore: $toBefore,
                toAfter: $toBalance->available,
                actorId: $actorId,
                reason: "Allocation #{$allocation->allocation_ref}",
            );

            return $allocation;
        });
    }

    // â”€â”€ Return â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Return seats up the hierarchy (Agent â†’ Distributor, Distributor â†’ Tenant, etc.)
     */
    public function returnSeats(
        FlightInventory $inventory,
        string $cabin,
        HolderType $fromType,
        ?int $fromId,
        HolderType $toType,
        ?int $toId,
        int $seats,
        ?FlightInventoryAllocation $allocation = null,
        ?string $reason = null,
        ?int $actorId = null,
    ): FlightInventoryTransaction {
        // Verify return is upward in hierarchy
        if ($fromType->hierarchyLevel() <= $toType->hierarchyLevel()) {
            throw new RuntimeException(
                "Return must go up the hierarchy. Cannot return from {$fromType->label()} to {$toType->label()}."
            );
        }

        return DB::transaction(function () use (
            $inventory, $cabin, $fromType, $fromId, $toType, $toId,
            $seats, $allocation, $reason, $actorId
        ) {
            $fromBalance = $this->lockBalance($inventory, $cabin, $fromType, $fromId);

            if ($fromBalance->available < $seats) {
                throw new RuntimeException(
                    "Insufficient available seats to return. Has {$fromBalance->available}, needs {$seats}."
                );
            }

            $fromBefore = $fromBalance->available;
            $fromBalance->decrement('available', $seats);
            $fromBalance->decrement('total_allocated', $seats);
            $fromBalance->refresh();

            $toBalance = $this->lockBalance($inventory, $cabin, $toType, $toId);
            $toBefore  = $toBalance->available;
            $toBalance->increment('available', $seats);
            $toBalance->refresh();

            // Mark allocation as reversed if provided
            if ($allocation) {
                $allocation->update([
                    'status'     => AllocationStatus::REVERSED->value,
                    'notes'      => trim(($allocation->notes ?? '') . "\nReturned: $reason"),
                ]);
            }

            return $this->writeTransaction(
                type: TransactionType::CHANNEL_RETURN,
                inventory: $inventory,
                cabin: $cabin,
                allocationId: $allocation?->id,
                fromType: $fromType,
                fromId: $fromId,
                toType: $toType,
                toId: $toId,
                delta: $seats,
                fromBefore: $fromBefore,
                fromAfter: $fromBalance->available,
                toBefore: $toBefore,
                toAfter: $toBalance->available,
                actorId: $actorId,
                reason: $reason ?? 'Return',
            );
        });
    }

    // â”€â”€ Booking Reservation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Temporarily reserve seats for the booking pipeline.
     * Moves seats from available â†’ reserved in the holder's balance.
     */
    public function reserveSeats(
        FlightInventory $inventory,
        string $cabin,
        HolderType $holderType,
        ?int $holderId,
        int $seats,
        string $sessionToken,
        int $ttlSeconds = 600,
        ?int $reservedBy = null,
    ): FlightInventoryReservation {
        return DB::transaction(function () use (
            $inventory, $cabin, $holderType, $holderId, $seats,
            $sessionToken, $ttlSeconds, $reservedBy
        ) {
            $balance = $this->lockBalance($inventory, $cabin, $holderType, $holderId);

            if ($balance->available < $seats) {
                throw new RuntimeException(
                    "Not enough seats available for reservation."
                );
            }

            $balance->decrement('available', $seats);
            $balance->increment('reserved', $seats);

            $reservation = FlightInventoryReservation::create([
                'tenant_id'           => $inventory->tenant_id,
                'flight_inventory_id' => $inventory->id,
                'cabin_class'         => $cabin,
                'holder_type'         => $holderType->value,
                'holder_id'           => $holderId,
                'seats_reserved'      => $seats,
                'status'              => 'ACTIVE',
                'session_token'       => $sessionToken,
                'reserved_by'         => $reservedBy,
                'expires_at'          => now()->addSeconds($ttlSeconds),
            ]);

            $this->writeTransaction(
                type: TransactionType::BOOKING,
                inventory: $inventory,
                cabin: $cabin,
                fromType: $holderType,
                fromId: $holderId,
                toType: HolderType::SYSTEM,
                toId: null,
                delta: -$seats,
                actorId: $reservedBy,
                reason: "Reservation {$reservation->reservation_ref}",
            );

            return $reservation;
        });
    }

    /**
     * Confirm a reservation â€” moves seats from reserved â†’ booked.
     */
    public function confirmReservation(
        FlightInventoryReservation $reservation,
        string $bookingReference,
        ?int $actorId = null,
    ): FlightInventoryReservation {
        return DB::transaction(function () use ($reservation, $bookingReference, $actorId) {
            if (! $reservation->isActive()) {
                throw new RuntimeException('Reservation is not active or has expired.');
            }

            $balance = $this->lockBalance(
                $reservation->flightInventory,
                $reservation->cabin_class,
                $reservation->holder_type,
                $reservation->holder_id,
            );

            $balance->decrement('reserved', $reservation->seats_reserved);
            $balance->increment('booked', $reservation->seats_reserved);

            $reservation->update([
                'status'            => 'CONFIRMED',
                'booking_reference' => $bookingReference,
                'confirmed_at'      => now(),
            ]);

            $this->writeTransaction(
                type: TransactionType::BOOKING,
                inventory: $reservation->flightInventory,
                cabin: $reservation->cabin_class,
                fromType: HolderType::SYSTEM,
                fromId: null,
                toType: $reservation->holder_type,
                toId: $reservation->holder_id,
                delta: 0, // no available change; reservedâ†’booked swap
                actorId: $actorId,
                reason: "Confirmed booking {$bookingReference}",
            );

            return $reservation->refresh();
        });
    }

    /**
     * Release a reservation â€” moves seats from reserved â†’ available.
     */
    public function releaseReservation(
        FlightInventoryReservation $reservation,
        string $reason = 'Booking abandoned',
        ?int $actorId = null,
    ): FlightInventoryReservation {
        return DB::transaction(function () use ($reservation, $reason, $actorId) {
            $balance = $this->lockBalance(
                $reservation->flightInventory,
                $reservation->cabin_class,
                $reservation->holder_type,
                $reservation->holder_id,
            );

            $balance->decrement('reserved', $reservation->seats_reserved);
            $balance->increment('available', $reservation->seats_reserved);

            $reservation->update([
                'status'      => 'RELEASED',
                'released_at' => now(),
            ]);

            $this->writeTransaction(
                type: TransactionType::RELEASE,
                inventory: $reservation->flightInventory,
                cabin: $reservation->cabin_class,
                fromType: HolderType::SYSTEM,
                fromId: null,
                toType: $reservation->holder_type,
                toId: $reservation->holder_id,
                delta: $reservation->seats_reserved,
                actorId: $actorId,
                reason: $reason,
            );

            return $reservation->refresh();
        });
    }

    // â”€â”€ Expiry (Cron) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Auto-expire allocations past their expires_at and return seats to parent.
     * Called by a scheduled job.
     */
    public function expireAllocations(): int
    {
        $expired = FlightInventoryAllocation::expired()->get();
        $count   = 0;

        foreach ($expired as $allocation) {
            try {
                $inventory = $allocation->flightInventory;
                $this->returnSeats(
                    inventory: $inventory,
                    cabin: $allocation->cabin_class,
                    fromType: HolderType::from($allocation->to_holder_type->value),
                    fromId: $allocation->to_holder_id,
                    toType: HolderType::from($allocation->from_holder_type->value),
                    toId: $allocation->from_holder_id,
                    seats: $allocation->seats_allocated,
                    allocation: $allocation,
                    reason: 'Auto-expiry',
                );

                $this->writeTransaction(
                    type: TransactionType::EXPIRY,
                    inventory: $inventory,
                    cabin: $allocation->cabin_class,
                    allocationId: $allocation->id,
                    fromType: HolderType::from($allocation->to_holder_type->value),
                    fromId: $allocation->to_holder_id,
                    toType: HolderType::from($allocation->from_holder_type->value),
                    toId: $allocation->from_holder_id,
                    delta: $allocation->seats_allocated,
                    reason: 'Auto-expiry',
                );

                $count++;
            } catch (\Throwable $e) {
                Log::error('FlightInventoryAllocationEngine: expiry failed', [
                    'allocation_id' => $allocation->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Expire active reservations that have passed their TTL.
     */
    public function expireReservations(): int
    {
        $expired = FlightInventoryReservation::expired()->get();
        $count   = 0;

        foreach ($expired as $reservation) {
            try {
                $this->releaseReservation($reservation, 'Reservation TTL expired');
                $reservation->update(['status' => 'EXPIRED']);
                $count++;
            } catch (\Throwable $e) {
                Log::error('FlightInventoryAllocationEngine: reservation expiry failed', [
                    'reservation_id' => $reservation->id,
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    // â”€â”€ Ledger â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Return the full audit ledger for a flight inventory, optionally filtered by holder.
     */
    public function getLedger(
        FlightInventory $inventory,
        ?HolderType $holderType = null,
        ?int $holderId = null,
        int $perPage = 50,
    ) {
        $query = FlightInventoryTransaction::where('flight_inventory_id', $inventory->id)
            ->with('allocation')
            ->orderByDesc('created_at');

        if ($holderType) {
            $query->forHolder($holderType->value, $holderId);
        }

        return $query->paginate($perPage);
    }

    // â”€â”€ Private Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Pessimistic lock on a balance row.  Creates the row if it doesn't exist yet.
     */
    private function lockBalance(
        FlightInventory $inventory,
        string $cabin,
        HolderType $holderType,
        ?int $holderId,
    ): FlightInventoryBalance {
        $tenantId = $holderType === HolderType::CENTRAL ? null : ($holderType === HolderType::TENANT ? $holderId : $inventory->tenant_id);
        $initialSeats = $this->initialCentralSeats($inventory, $cabin, $holderType);

        FlightInventoryBalance::withoutGlobalTenant()->firstOrCreate(
            [
                'flight_inventory_id' => $inventory->id,
                'holder_type'         => $holderType->value,
                'holder_id'           => $holderId,
                'cabin_class'         => $cabin,
            ],
            [
                'tenant_id'       => $tenantId,
                'total_allocated' => $initialSeats,
                'available'       => $initialSeats,
                'reserved'        => 0,
                'booked'          => 0,
                'frozen'          => 0,
                'is_active'       => true,
            ]
        );

        return FlightInventoryBalance::withoutGlobalTenant()
            ->where('flight_inventory_id', $inventory->id)
            ->where('holder_type', $holderType->value)
            ->where('holder_id', $holderId)
            ->where('cabin_class', $cabin)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * A flight's central pool starts with the seats it has on sale in its own
     * cabin. Nothing else ever filled it, so the first allocation from a new
     * flight always failed with "Central Pool has 0 available".
     */
    private function initialCentralSeats(FlightInventory $inventory, string $cabin, HolderType $holderType): int
    {
        if ($holderType !== HolderType::CENTRAL || strcasecmp((string) $inventory->cabin_class, $cabin) !== 0) {
            return 0;
        }

        return max(0, (int) $inventory->available_seats);
    }

    private function findOrCreateBalance(
        FlightInventory $inventory,
        string $cabin,
        HolderType $holderType,
        ?int $holderId,
        string $allocationMode = 'fixed',
        int $floorSeats = 0,
    ): FlightInventoryBalance {
        $tenantId = $holderType === HolderType::CENTRAL ? null : ($holderType === HolderType::TENANT ? $holderId : $inventory->tenant_id);

        return FlightInventoryBalance::withoutGlobalTenant()->firstOrCreate(
            [
                'flight_inventory_id' => $inventory->id,
                'holder_type'         => $holderType->value,
                'holder_id'           => $holderId,
                'cabin_class'         => $cabin,
            ],
            [
                'tenant_id'       => $tenantId,
                'total_allocated' => 0,
                'available'       => 0,
                'reserved'        => 0,
                'booked'          => 0,
                'frozen'          => 0,
                'allocation_mode' => $allocationMode,
                'floor_seats'     => $floorSeats,
                'is_active'       => true,
            ]
        );
    }

    private function writeTransaction(
        TransactionType $type,
        FlightInventory $inventory,
        string $cabin,
        ?int $allocationId = null,
        HolderType $fromType = HolderType::SYSTEM,
        ?int $fromId = null,
        HolderType $toType = HolderType::SYSTEM,
        ?int $toId = null,
        int $delta = 0,
        int $fromBefore = 0,
        int $fromAfter = 0,
        int $toBefore = 0,
        int $toAfter = 0,
        ?int $actorId = null,
        ?string $reason = null,
    ): FlightInventoryTransaction {
        return FlightInventoryTransaction::create([
            'tenant_id'           => $inventory->tenant_id,
            'flight_inventory_id' => $inventory->id,
            'cabin_class'         => $cabin,
            'allocation_id'       => $allocationId,
            'transaction_type'    => $type->value,
            'from_holder_type'    => $fromType->value,
            'from_holder_id'      => $fromId,
            'to_holder_type'      => $toType->value,
            'to_holder_id'        => $toId,
            'seats_delta'         => $delta,
            'from_balance_before' => $fromBefore,
            'from_balance_after'  => $fromAfter,
            'to_balance_before'   => $toBefore,
            'to_balance_after'    => $toAfter,
            'actor_id'            => $actorId ?? auth()->id(),
            'actor_ip'            => request()->ip(),
            'reason'              => $reason,
        ]);
    }
}
