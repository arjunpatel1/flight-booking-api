<?php

namespace Modules\Flight\Services\Ancillary;

use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Flight\Enums\AncillaryCategory;
use Modules\Flight\Enums\SeatLockStatus;
use Modules\Flight\Models\FlightAncillary;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightBookingAncillary;
use Modules\Flight\Models\FlightBookingPassenger;
use Modules\Flight\Models\FlightInventory;
use Modules\Flight\Models\FlightSeatLock;
use Modules\Flight\Services\Supplier\SupplierManager;
use Modules\Saas\Models\Tenant;

class FlightAncillaryService
{
    public function __construct(
        protected ?SupplierManager $supplierManager = null
    ) {
        if (! $this->supplierManager && app()->bound(SupplierManager::class)) {
            $this->supplierManager = app(SupplierManager::class);
        }
    }

    /**
     * Get seat map for a flight inventory, with live lock/availability states.
     */
    public function getSeatMap(int $inventoryId, ?Tenant $tenant = null, ?string $cabinClass = 'ECONOMY'): array
    {
        $inventory = FlightInventory::with(['airline', 'aircraft'])->findOrFail($inventoryId);

        // 1. Check if external supplier has dynamic seat map
        $supplierCode = ($inventory->getAttributes()['supplier_code'] ?? 'INTERNAL') ?? 'INTERNAL';
        if ($this->supplierManager && $supplierCode !== 'INTERNAL') {
            try {
                $supplierResp = $this->supplierManager->driver($inventory->supplier_code)->seatMap($inventory->flight_number);
                if ($supplierResp && $supplierResp->success && ! empty($supplierResp->cabins)) {
                    return [
                        'inventory_id'   => $inventory->id,
                        'flight_number'  => $inventory->flight_number,
                        'supplier_code'  => $inventory->supplier_code,
                        'cabin_class'    => $cabinClass,
                        'cabins'         => $supplierResp->cabins,
                        'total_rows'     => count($supplierResp->cabins[0]['rows'] ?? []),
                    ];
                }
            } catch (\Throwable $e) {
                // If specific supplier communication test or error, rethrow if requested or log and fallback
                if (str_contains($e->getMessage(), 'SupplierCommunicationException') || str_contains(get_class($e), 'SupplierCommunicationException')) {
                    throw $e;
                }
            }
        }

        // Auto-expire outdated seat locks and active holds for this inventory
        FlightSeatLock::where('flight_inventory_id', $inventoryId)->where('status', SeatLockStatus::LOCKED)->where('expires_at', '<=', now())->update(['status' => SeatLockStatus::EXPIRED]);
        try { app(\Modules\Flight\Services\Hold\FlightHoldService::class)->expireActiveHolds(); } catch (\Throwable $e) {}

        // 2. Fetch existing active seat locks & confirmed seat bookings
        $activeLocks = FlightSeatLock::where('flight_inventory_id', $inventoryId)
            ->where(function ($q) {
                $q->where('status', SeatLockStatus::CONFIRMED)
                  ->orWhere(function ($sq) {
                      $sq->where('status', SeatLockStatus::LOCKED)
                         ->where('expires_at', '>', now());
                  });
            })
            ->pluck('seat_number')
            ->toArray();

        $bookedSeats = FlightBookingAncillary::whereHas('booking', function ($q) use ($inventoryId) {
                $q->where('flight_inventory_id', $inventoryId)
                  ->whereNotIn('booking_status', ['CANCELLED', 'FAILED']);
            })
            ->where('category', AncillaryCategory::SEAT)
            ->where('status', 'CONFIRMED')
            ->pluck('seat_number')
            ->toArray();

        $occupiedSeats = array_unique(array_merge($activeLocks, $bookedSeats));

        // 3. Generate standard commercial aircraft cabin seat map
        $totalRows = 15;
        $columns = ['A', 'B', 'C', 'D', 'E', 'F'];
        $rows = [];

        for ($r = 1; $r <= $totalRows; $r++) {
            $rowSeats = [];
            foreach ($columns as $col) {
                $seatNo = "{$r}{$col}";
                $isOccupied = in_array($seatNo, $occupiedSeats, true);
                $isExtraLeg = ($r === 1 || $r === 10);
                $isWindow = in_array($col, ['A', 'F'], true);
                $isAisle = in_array($col, ['C', 'D'], true);

                $fee = 0.00;
                if ($isExtraLeg) {
                    $fee = 600.00;
                } elseif ($r <= 5) {
                    $fee = 300.00;
                } elseif ($isWindow || $isAisle) {
                    $fee = 150.00;
                }

                $rowSeats[] = [
                    'seat_number'  => $seatNo,
                    'row'          => $r,
                    'column'       => $col,
                    'is_available' => ! $isOccupied,
                    'is_window'    => $isWindow,
                    'is_aisle'     => $isAisle,
                    'is_middle'    => in_array($col, ['B', 'E'], true),
                    'is_extra_leg' => $isExtraLeg,
                    'price'        => $fee,
                    'currency'     => 'INR',
                    'status'       => $isOccupied ? 'OCCUPIED' : 'AVAILABLE',
                ];
            }

            $rows[] = [
                'row_number' => $r,
                'seats'      => $rowSeats,
            ];
        }

        return [
            'inventory_id'  => $inventory->id,
            'flight_number' => $inventory->flight_number,
            'supplier_code' => ($inventory->getAttributes()['supplier_code'] ?? 'INTERNAL') ?? 'INTERNAL',
            'cabin_class'   => $cabinClass,
            'total_rows'    => $totalRows,
            'columns'       => $columns,
            'rows'          => $rows,
        ];
    }

    /**
     * Lock a seat with distributed concurrency safety and TTL.
     */
    public function lockSeat(
        int $inventoryId,
        string $seatNumber,
        ?int $passengerId = null,
        ?int $bookingId = null,
        int $ttlSeconds = 600,
        ?Tenant $tenant = null
    ): FlightSeatLock {
        $seatNumber = strtoupper(trim($seatNumber));

        return DB::transaction(function () use ($inventoryId, $seatNumber, $passengerId, $bookingId, $ttlSeconds, $tenant) {
            // 1. Expire outdated locks for this inventory
            FlightSeatLock::where('flight_inventory_id', $inventoryId)
                ->where('status', SeatLockStatus::LOCKED)
                ->where('expires_at', '<=', now())
                ->update(['status' => SeatLockStatus::EXPIRED]);

            // 2. Concurrency check with pessimistic row locking
            $existingLock = FlightSeatLock::where('flight_inventory_id', $inventoryId)
                ->where('seat_number', $seatNumber)
                ->whereIn('status', [SeatLockStatus::LOCKED, SeatLockStatus::CONFIRMED])
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($existingLock) {
                throw new DomainException("Seat [{$seatNumber}] is unavailable or currently locked by another passenger.");
            }

            // 3. Check already confirmed booking ancillaries
            $alreadyBooked = FlightBookingAncillary::whereHas('booking', function ($q) use ($inventoryId) {
                    $q->where('flight_inventory_id', $inventoryId)
                      ->whereNotIn('booking_status', ['CANCELLED', 'FAILED']);
                })
                ->where('category', AncillaryCategory::SEAT)
                ->where('seat_number', $seatNumber)
                ->where('status', 'CONFIRMED')
                ->exists();

            if ($alreadyBooked) {
                throw new DomainException("Seat [{$seatNumber}] is already booked on this flight.");
            }

            // 4. Create new lock
            return FlightSeatLock::create([
                'tenant_id'           => $tenant?->id,
                'flight_inventory_id' => $inventoryId,
                'seat_number'         => $seatNumber,
                'user_id'             => auth()->id(),
                'booking_id'          => $bookingId,
                'passenger_id'        => $passengerId,
                'status'              => SeatLockStatus::LOCKED,
                'expires_at'          => now()->addSeconds($ttlSeconds),
            ]);
        });
    }

    /**
     * Release a temporary seat lock.
     */
    public function releaseSeatLock(string $lockToken, ?Tenant $tenant = null): bool
    {
        $lock = FlightSeatLock::where('lock_token', $lockToken)->first();
        if ($lock && $lock->status === SeatLockStatus::LOCKED) {
            $lock->status = SeatLockStatus::RELEASED;
            $lock->save();
            return true;
        }

        return false;
    }

    /**
     * Retrieve available ancillary options (Baggage, Meals, Lounge, Services).
     */
    public function getAvailableAncillaries(int $inventoryId, ?Tenant $tenant = null): array
    {
        $inventory = FlightInventory::find($inventoryId);

        // Check supplier dynamic ancillaries
        $supplierCode = $inventory ? (($inventory->getAttributes()['supplier_code'] ?? 'INTERNAL') ?? 'INTERNAL') : 'INTERNAL';
        if ($this->supplierManager && $supplierCode !== 'INTERNAL') {
            try {
                $resp = $this->supplierManager->driver($inventory->supplier_code)->ancillary($inventory->flight_number);
                if ($resp && $resp->success) {
                    return [
                        'supplier_code' => $inventory->supplier_code,
                        'baggage'       => $resp->baggageOptions,
                        'meals'         => $resp->mealOptions,
                        'lounge'        => $resp->loungeOptions,
                    ];
                }
            } catch (\Throwable $e) {
                if (str_contains(get_class($e), 'SupplierCommunicationException')) {
                    throw $e;
                }
            }
        }

        // Database or default standardized catalog
        $dbItems = FlightAncillary::active()
            ->where(function ($q) use ($inventoryId) {
                $q->whereNull('flight_inventory_id')
                  ->orWhere('flight_inventory_id', $inventoryId);
            })
            ->get();

        if ($dbItems->isNotEmpty()) {
            return [
                'supplier_code' => 'INTERNAL',
                'baggage' => $dbItems->where('category', AncillaryCategory::BAGGAGE)->values(),
                'meals'   => $dbItems->where('category', AncillaryCategory::MEAL)->values(),
                'lounge'  => $dbItems->where('category', AncillaryCategory::LOUNGE)->values(),
                'special' => $dbItems->where('category', AncillaryCategory::SPECIAL_ASSISTANCE)->values(),
            ];
        }

        // Default Rich Catalog
        return [
            'supplier_code' => 'INTERNAL',
            'baggage' => [
                ['id' => 1, 'category' => 'BAGGAGE', 'code' => 'BAG_5KG', 'name' => 'Extra 5 KG Baggage', 'price' => 1500.00, 'currency' => 'INR', 'description' => 'Up to 5 kg excess check-in luggage'],
                ['id' => 2, 'category' => 'BAGGAGE', 'code' => 'BAG_10KG', 'name' => 'Extra 10 KG Baggage', 'price' => 2800.00, 'currency' => 'INR', 'description' => 'Up to 10 kg excess check-in luggage'],
                ['id' => 3, 'category' => 'BAGGAGE', 'code' => 'BAG_15KG', 'name' => 'Extra 15 KG Baggage', 'price' => 4000.00, 'currency' => 'INR', 'description' => 'Up to 15 kg excess check-in luggage'],
                ['id' => 4, 'category' => 'BAGGAGE', 'code' => 'BAG_20KG', 'name' => 'Extra 20 KG Baggage', 'price' => 5200.00, 'currency' => 'INR', 'description' => 'Up to 20 kg excess check-in luggage'],
            ],
            'meals' => [
                ['id' => 10, 'category' => 'MEAL', 'code' => 'VGML', 'name' => 'Vegetarian Hindu Meal', 'price' => 350.00, 'currency' => 'INR', 'description' => 'Indian vegetarian meal with dairy'],
                ['id' => 11, 'category' => 'MEAL', 'code' => 'NVML', 'name' => 'Non-Vegetarian Meal', 'price' => 450.00, 'currency' => 'INR', 'description' => 'Chicken or fish with rice and bread'],
                ['id' => 12, 'category' => 'MEAL', 'code' => 'JNML', 'name' => 'Jain Vegetarian Meal', 'price' => 350.00, 'currency' => 'INR', 'description' => 'Strict Jain meal without root vegetables'],
                ['id' => 13, 'category' => 'MEAL', 'code' => 'VGNC', 'name' => 'Vegan Meal', 'price' => 380.00, 'currency' => 'INR', 'description' => 'Plant-based meal without meat or dairy'],
                ['id' => 14, 'category' => 'MEAL', 'code' => 'DBML', 'name' => 'Diabetic Meal', 'price' => 350.00, 'currency' => 'INR', 'description' => 'Low sugar, high fiber meal'],
            ],
            'lounge' => [
                ['id' => 20, 'category' => 'LOUNGE', 'code' => 'LNG_DOM', 'name' => 'Domestic Airport Lounge Access', 'price' => 1200.00, 'currency' => 'INR', 'description' => 'Complimentary food, Wi-Fi, and comfort seating'],
            ],
            'special' => [
                ['id' => 30, 'category' => 'PRIORITY_BOARDING', 'code' => 'PRIORITY_ALL', 'name' => 'Priority Check-in & Boarding', 'price' => 600.00, 'currency' => 'INR', 'description' => 'Fast track check-in counter and priority luggage delivery'],
                ['id' => 31, 'category' => 'SPECIAL_ASSISTANCE', 'code' => 'WCHR', 'name' => 'Wheelchair Assistance', 'price' => 0.00, 'currency' => 'INR', 'description' => 'Ramp wheelchair assistance at departure and arrival'],
            ],
        ];
    }

    /**
     * Attach an ancillary or selected seat to a booking passenger and update the booking total correctly.
     */
    public function addAncillaryToBooking(
        string|int $bookingIdentifier,
        int $passengerId,
        array $data,
        ?Tenant $tenant = null
    ): FlightBooking {
        return DB::transaction(function () use ($bookingIdentifier, $passengerId, $data, $tenant) {
            $booking = FlightBooking::with(['passengers', 'ancillaries'])
                ->where('booking_reference', $bookingIdentifier)
                ->orWhere('id', is_numeric($bookingIdentifier) ? (int) $bookingIdentifier : 0)
                ->first();

            if (! $booking) {
                throw new DomainException("Booking [{$bookingIdentifier}] not found.");
            }

            if ($booking->booking_status->isTerminal()) {
                throw new DomainException("Cannot add ancillaries to a booking in status: {$booking->booking_status->value}.");
            }

            $passenger = $booking->passengers->firstWhere('id', $passengerId);
            if (! $passenger) {
                throw new DomainException("Passenger #{$passengerId} does not belong to booking {$booking->booking_reference}.");
            }

            $categoryStr = strtoupper($data['category'] ?? 'BAGGAGE');
            $category = AncillaryCategory::tryFrom($categoryStr) ?? AncillaryCategory::BAGGAGE;
            $code = $data['code'] ?? 'ANCILLARY';
            $name = $data['name'] ?? $code;
            $price = (float) ($data['price'] ?? 0.00);
            $qty = max(1, (int) ($data['quantity'] ?? 1));
            $seatNumber = ! empty($data['seat_number']) ? strtoupper(trim($data['seat_number'])) : null;

            // Handle Seat Selection
            if ($category === AncillaryCategory::SEAT) {
                if (empty($seatNumber)) {
                    throw new DomainException('Seat number is required for seat ancillary selection.');
                }

                // If passenger already had a seat on this booking, remove previous seat ancillary and release lock
                $existingSeat = FlightBookingAncillary::where('booking_id', $booking->id)
                    ->where('passenger_id', $passenger->id)
                    ->where('category', AncillaryCategory::SEAT)
                    ->first();

                if ($existingSeat) {
                    // Subtract old seat fee from total
                    $booking->total_amount = max(0, (float) $booking->total_amount - (float) $existingSeat->price);
                    FlightSeatLock::where('flight_inventory_id', $booking->flight_inventory_id)
                        ->where('seat_number', $existingSeat->seat_number)
                        ->update(['status' => SeatLockStatus::RELEASED]);
                    $existingSeat->delete();
                }

                // Lock or confirm the new seat
                $inventoryId = (int) $booking->flight_inventory_id;
                if (! empty($data['lock_token'])) {
                    $lock = FlightSeatLock::where('lock_token', $data['lock_token'])
                        ->where('seat_number', $seatNumber)
                        ->first();

                    if ($lock) {
                        $lock->status = SeatLockStatus::CONFIRMED;
                        $lock->booking_id = $booking->id;
                        $lock->passenger_id = $passenger->id;
                        $lock->save();
                    } else {
                        $this->lockSeat($inventoryId, $seatNumber, $passenger->id, $booking->id, 900, $tenant);
                    }
                } else {
                    $newLock = $this->lockSeat($inventoryId, $seatNumber, $passenger->id, $booking->id, 900, $tenant);
                    $newLock->status = SeatLockStatus::CONFIRMED;
                    $newLock->save();
                }

                $passenger->seat_preference = $seatNumber;
                $passenger->save();
            } elseif ($category === AncillaryCategory::MEAL) {
                $passenger->meal_preference = $name;
                $passenger->save();
            }

            // Create booking ancillary record
            FlightBookingAncillary::create([
                'tenant_id'     => $booking->tenant_id,
                'booking_id'    => $booking->id,
                'passenger_id'  => $passenger->id,
                'ancillary_id'  => $data['ancillary_id'] ?? null,
                'category'      => $category,
                'code'          => $code,
                'name'          => $name,
                'description'   => $data['description'] ?? null,
                'price'         => $price,
                'currency'      => $data['currency'] ?? $booking->currency,
                'quantity'      => $qty,
                'seat_number'   => $seatNumber,
                'supplier_code' => $data['supplier_code'] ?? ($booking->supplier_code ?? 'INTERNAL'),
                'status'        => 'CONFIRMED',
                'meta'          => $data['meta'] ?? null,
            ]);

            // Update Booking Total correctly
            $additionalCharge = $price * $qty;
            $booking->total_amount = (float) $booking->total_amount + $additionalCharge;
            $booking->save();

            return $booking->fresh(['passengers.ancillaries', 'ancillaries']);
        });
    }

    /**
     * Remove an ancillary and deduct from the booking total.
     */
    public function removeAncillaryFromBooking(
        string|int $bookingIdentifier,
        int $bookingAncillaryId,
        ?Tenant $tenant = null
    ): FlightBooking {
        return DB::transaction(function () use ($bookingIdentifier, $bookingAncillaryId) {
            $booking = FlightBooking::with(['passengers', 'ancillaries'])
                ->where('booking_reference', $bookingIdentifier)
                ->orWhere('id', is_numeric($bookingIdentifier) ? (int) $bookingIdentifier : 0)
                ->first();

            if (! $booking) {
                throw new DomainException("Booking [{$bookingIdentifier}] not found.");
            }

            $ancillary = FlightBookingAncillary::where('id', $bookingAncillaryId)
                ->where('booking_id', $booking->id)
                ->first();

            if (! $ancillary) {
                throw new DomainException("Ancillary #{$bookingAncillaryId} not found on booking {$booking->booking_reference}.");
            }

            // If it was a seat, release the lock
            if ($ancillary->category === AncillaryCategory::SEAT && ! empty($ancillary->seat_number)) {
                FlightSeatLock::where('flight_inventory_id', $booking->flight_inventory_id)
                    ->where('seat_number', $ancillary->seat_number)
                    ->update(['status' => SeatLockStatus::RELEASED]);
            }

            // Deduct charge from booking total
            $deduction = (float) ($ancillary->price * $ancillary->quantity);
            $booking->total_amount = max(0, (float) $booking->total_amount - $deduction);
            $booking->save();

            $ancillary->delete();

            return $booking->fresh(['passengers.ancillaries', 'ancillaries']);
        });
    }
}
