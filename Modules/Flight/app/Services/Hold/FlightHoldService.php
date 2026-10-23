<?php

namespace Modules\Flight\Services\Hold;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Flight\Enums\HolderType;
use Modules\Flight\Enums\HoldStatus;
use Modules\Flight\Models\FlightFare;
use Modules\Flight\Models\FlightFareQuote;
use Modules\Flight\Models\FlightHold;
use Modules\Flight\Models\FlightInventory;
use Modules\Flight\Models\FlightInventoryBalance;
use Modules\Flight\Services\Inventory\FlightInventoryAllocationEngine;
use Modules\Flight\Services\Pricing\FlightPricingEngine;
use Modules\Saas\Models\Tenant;
use Modules\User\Models\User;
use RuntimeException;

class FlightHoldService
{
    public function __construct(
        protected FlightPricingEngine $pricingEngine,
        protected FlightInventoryAllocationEngine $allocationEngine,
    ) {}

    /**
     * Revalidate fare, taxes, markups and final customer price.
     * Detects stale prices and informs the client.
     */
    public function revalidatePrice(array $input, ?Tenant $tenant = null): array
    {
        $fareId = $input['fare_id'] ?? ($input['flight_fare_id'] ?? null);
        $expectedPrice = isset($input['expected_price']) ? (float) $input['expected_price'] : null;
        $quantity = max(1, (int) ($input['quantity'] ?? 1));

        $fare = null;
        if ($fareId) {
            $fare = FlightFare::with(['fareFamily', 'flightInventory.airline', 'flightInventory.originAirport', 'flightInventory.destinationAirport'])
                ->find($fareId);
        }

        if (! $fare) {
            return [
                'revalidated'         => false,
                'available'           => false,
                'stale_price_detected'=> false,
                'price_changed'       => false,
                'error_code'          => 'FARE_NOT_FOUND',
                'message'             => 'The requested flight fare could not be found or has expired.',
            ];
        }

        $isFareActive = ($fare->status === 'active') || (! empty($fare->is_active));
        if (! $isFareActive) {
            return [
                'revalidated'         => false,
                'available'           => false,
                'stale_price_detected'=> false,
                'price_changed'       => false,
                'error_code'          => 'FARE_INACTIVE',
                'message'             => 'This fare is no longer active for booking.',
            ];
        }

        if ($fare->flightInventory && ! $this->isBookingWindowOpen($fare->flightInventory, $input['departure_date'] ?? null)) {
            return [
                'revalidated'          => false,
                'available'            => false,
                'stale_price_detected' => false,
                'price_changed'        => false,
                'error_code'           => 'BOOKING_WINDOW_CLOSED',
                'message'              => 'This flight is no longer available to book because it is inside the booking cutoff period.',
            ];
        }

        // Date validity check
        if ($fare->valid_from && Carbon::parse($fare->valid_from)->isFuture()) {
            return [
                'revalidated'         => false,
                'available'           => false,
                'stale_price_detected'=> false,
                'price_changed'       => false,
                'error_code'          => 'FARE_NOT_YET_VALID',
                'message'             => 'This fare is not yet valid for booking.',
            ];
        }

        $validUntil = $fare->valid_until ?? ($fare->valid_to ?? null);
        if ($validUntil && Carbon::parse($validUntil)->isPast()) {
            return [
                'revalidated'         => false,
                'available'           => false,
                'stale_price_detected'=> false,
                'price_changed'       => false,
                'error_code'          => 'FARE_EXPIRED',
                'message'             => 'This fare has expired.',
            ];
        }

        // Pricing pipeline calculation
        $basePrice = (float) ($fare->supplier_fare ?? ($fare->base_fare ?? ($fare->base_price ?? 0)));
        $pricingInput = [
            'supplier_fare'            => $basePrice,
            'taxes'                    => (float) ($fare->taxes ?? 0),
            'supplier_fees'            => (float) ($fare->supplier_fees ?? 0),
            'pricing_rules_adjustment' => (float) ($fare->pricing_rules_adjustment ?? 0),
            'platform_markup'          => (float) ($fare->platform_markup ?? 0),
            'tenant_markup'            => (float) ($fare->tenant_markup ?? 0),
            'client_markup'            => (float) ($fare->client_markup ?? 0),
            'convenience_fee'          => (float) ($fare->convenience_fee ?? 0),
            'gst'                      => (float) ($fare->gst ?? 0),
            'discount'                 => (float) ($fare->discount ?? 0),
        ];

        $breakdown = $this->pricingEngine->calculate($pricingInput);
        $unitPrice = $breakdown['final_customer_price'];
        $totalCustomerPrice = round($unitPrice * $quantity, 2);

        $priceChanged = false;
        $priceDifference = 0.0;

        if ($expectedPrice !== null) {
            $priceDifference = round($totalCustomerPrice - $expectedPrice, 2);
            $priceChanged = abs($priceDifference) >= 0.01;
        }

        return [
            'revalidated'          => true,
            'available'            => true,
            'price_changed'        => $priceChanged,
            'stale_price_detected' => $priceChanged,
            'old_price'            => $expectedPrice,
            'new_price'            => $totalCustomerPrice,
            'price_difference'     => $priceDifference,
            'unit_price'           => $unitPrice,
            'total_price'          => $totalCustomerPrice,
            'currency'             => $fare->currency ?? 'INR',
            'quantity'             => $quantity,
            'fare_id'              => $fare->id,
            'inventory_id'         => $fare->flight_inventory_id,
            'fare_family'          => [
                'id'          => $fare->fareFamily?->id,
                'code'        => $fare->fareFamily?->code,
                'name'        => $fare->fareFamily?->name,
                'description' => $fare->fareFamily?->description,
            ],
            'rules' => [
                'cabin_class'   => $fare->cabin_class,
                'baggage_kg'    => $fare->baggage_kg ?? 15,
                'is_refundable' => (bool) ($fare->is_refundable ?? false),
                'is_changeable' => (bool) ($fare->is_changeable ?? false),
            ],
            'pricing_breakdown'    => $breakdown,
            'timestamp'            => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Revalidate inventory availability for specific flight and cabin.
     * Prevents overselling.
     */
    public function revalidateInventory(array $input, ?Tenant $tenant = null): array
    {
        $inventoryId = $input['inventory_id'] ?? ($input['flight_inventory_id'] ?? null);
        $cabin = strtoupper($input['cabin_class'] ?? 'ECONOMY');
        $quantity = max(1, (int) ($input['quantity'] ?? 1));
        $holderType = HolderType::tryFrom($input['holder_type'] ?? 'tenant') ?? HolderType::TENANT;
        $holderId = $input['holder_id'] ?? ($tenant?->id ?? null);

        $inventory = FlightInventory::find($inventoryId);
        if (! $inventory) {
            return [
                'available'       => false,
                'seats_available' => 0,
                'error_code'      => 'INVENTORY_NOT_FOUND',
                'message'         => 'Selected flight inventory not found.',
            ];
        }

        $isFlightActive = ($inventory->status === 'active') || (! empty($inventory->is_active));
        if (! $isFlightActive) {
            return [
                'available'       => false,
                'seats_available' => 0,
                'error_code'      => 'FLIGHT_INACTIVE',
                'message'         => 'Selected flight is no longer active.',
            ];
        }

        if (! $this->isBookingWindowOpen($inventory, $input['departure_date'] ?? null)) {
            return [
                'available'       => false,
                'seats_available' => 0,
                'error_code'      => 'BOOKING_WINDOW_CLOSED',
                'message'         => 'This flight is no longer available to book because it is inside the booking cutoff period.',
            ];
        }

        // Global flight seats check
        if ($inventory->available_seats < $quantity) {
            return [
                'available'       => false,
                'seats_available' => $inventory->available_seats,
                'error_code'      => 'INSUFFICIENT_FLIGHT_CAPACITY',
                'message'         => "Only {$inventory->available_seats} total seats available on this flight.",
            ];
        }

        // Tier / Holder balance check if central inventory allocations exist
        $balance = FlightInventoryBalance::where('flight_inventory_id', $inventory->id)
            ->where('cabin_class', $cabin)
            ->where('holder_type', $holderType->value)
            ->when($holderId, fn ($q) => $q->where('holder_id', $holderId))
            ->first();

        if ($balance) {
            if ($balance->available < $quantity) {
                return [
                    'available'       => false,
                    'seats_available' => $balance->available,
                    'error_code'      => 'INSUFFICIENT_TIER_SEATS',
                    'message'         => "Insufficient seats available in tier: {$balance->available} remaining.",
                ];
            }

            return [
                'available'       => true,
                'seats_available' => $balance->available,
                'cabin_class'     => $cabin,
                'inventory_id'    => $inventory->id,
            ];
        }

        return [
            'available'       => true,
            'seats_available' => $inventory->available_seats,
            'cabin_class'     => $cabin,
            'inventory_id'    => $inventory->id,
        ];
    }

    private function isBookingWindowOpen(FlightInventory $inventory, ?string $departureDate = null): bool
    {
        $tenant = $inventory->tenant_id
            ? Tenant::query()->withoutGlobalScopes()->find($inventory->tenant_id)
            : null;
        $minimumAdvanceDays = (int) data_get(
            $tenant?->settings,
            'flight_booking.minimum_advance_booking_days',
            0
        );

        // FlightInventory represents a reusable route and does not store the
        // selected travel date. That date is supplied by the search result.
        if (! $departureDate) {
            return true;
        }

        return Carbon::parse($departureDate)
            ->startOfDay()
            ->greaterThanOrEqualTo(Carbon::today()->addDays($minimumAdvanceDays));
    }

    /**
     * Create a temporary inventory hold protected by Redis distributed locking.
     * Prevents concurrent overselling, race conditions, and duplicate holds.
     */
    public function createHold(array $input, ?Tenant $tenant = null, ?User $user = null): FlightHold
    {
        $idempotencyKey = $input['idempotency_key'] ?? ($input['reference'] ?? null);
        if ($idempotencyKey) {
            $existingHold = FlightHold::where('idempotency_key', $idempotencyKey)
                ->where('status', HoldStatus::ACTIVE)
                ->where('expires_at', '>', Carbon::now())
                ->first();

            if ($existingHold) {
                return $existingHold;
            }
        }

        $inventoryId = $input['inventory_id'] ?? ($input['flight_inventory_id'] ?? null);
        $fareId = $input['fare_id'] ?? ($input['flight_fare_id'] ?? null);
        $cabin = strtoupper($input['cabin_class'] ?? 'ECONOMY');
        $quantity = max(1, (int) ($input['quantity'] ?? 1));
        $supplierCode = $input['supplier_code'] ?? 'INTERNAL';
        $reference = $input['reference'] ?? ('REF-' . strtoupper(Str::random(10)));
        $sessionToken = $input['session_token'] ?? Str::uuid()->toString();

        $lockTtl = (int) config('flight.lock_ttl', 10);
        $holdTtl = (int) config('flight.hold_ttl', 600); // 10 minutes

        $lockKey = "flight_hold_lock:inv:{$inventoryId}:{$cabin}";

        // Distributed Lock execution
        $callback = function () use (
            $input, $tenant, $user, $inventoryId, $fareId, $cabin, $quantity,
            $supplierCode, $reference, $sessionToken, $idempotencyKey, $holdTtl
        ) {
            return DB::transaction(function () use (
                $input, $tenant, $user, $inventoryId, $fareId, $cabin, $quantity,
                $supplierCode, $reference, $sessionToken, $idempotencyKey, $holdTtl
            ) {
                // 1. Revalidate inventory under lock
                $invCheck = $this->revalidateInventory([
                    'inventory_id' => $inventoryId,
                    'cabin_class'  => $cabin,
                    'quantity'     => $quantity,
                    'holder_id'    => $tenant?->id,
                    'departure_date' => $input['departure_date'] ?? null,
                ], $tenant);

                if (! $invCheck['available']) {
                    throw new RuntimeException($invCheck['message'] ?? 'Inventory unavailable for hold.');
                }

                $inventory = FlightInventory::lockForUpdate()->find($inventoryId);
                if (! $inventory || $inventory->available_seats < $quantity) {
                    throw new RuntimeException('Insufficient available seats on flight.');
                }

                $fare = null;
                if ($fareId) {
                    $fare = FlightFare::find($fareId);
                }

                // 2. Reserve in Allocation Engine if inventory balance exists or decrement flight available seats
                $reservation = null;
                $holderType = HolderType::TENANT;
                $holderId = $tenant?->id;

                $balanceExists = FlightInventoryBalance::where('flight_inventory_id', $inventory->id)
                    ->where('cabin_class', $cabin)
                    ->exists();

                if ($balanceExists) {
                    $reservation = $this->allocationEngine->reserveSeats(
                        inventory:    $inventory,
                        cabin:        $cabin,
                        holderType:   $holderType,
                        holderId:     $holderId,
                        seats:        $quantity,
                        sessionToken: $sessionToken,
                        ttlSeconds:   $holdTtl,
                        reservedBy:   $user?->id,
                    );
                } else {
                    // Direct flight inventory reservation
                    $inventory->decrement('available_seats', $quantity);
                }

                // 3. Create FlightHold entity
                $expiresAt = Carbon::now()->addSeconds($holdTtl);

                $hold = FlightHold::create([
                    'hold_id'             => 'HOLD-' . strtoupper(Str::random(10)),
                    'tenant_id'           => $tenant?->id ?? $inventory->tenant_id,
                    'user_id'             => $user?->id,
                    'flight_inventory_id' => $inventory->id,
                    'flight_fare_id'      => $fare?->id,
                    'supplier_code'       => $supplierCode,
                    'cabin_class'         => $cabin,
                    'quantity'            => $quantity,
                    'status'              => HoldStatus::ACTIVE,
                    'expires_at'          => $expiresAt,
                    'created_by'          => $user?->id,
                    'reference'           => $reference,
                    'idempotency_key'     => $idempotencyKey,
                    'reservation_id'      => $reservation?->id,
                    'meta'                => [
                        'session_token' => $sessionToken,
                        'flight_number' => $inventory->flight_number,
                        'origin'        => $inventory->originAirport?->iata_code,
                        'destination'   => $inventory->destinationAirport?->iata_code,
                        'departure'     => $inventory->departure_time,
                        'departure_date'=> $input['departure_date'] ?? null,
                        'fare_code'     => $fare?->fare_code,
                        'contact_email' => $input['contact_email'] ?? null,
                        'contact_phone' => $input['contact_phone'] ?? null,
                    ],
                ]);

                event(new \Modules\Flight\Events\FlightHoldCreated($hold));

                return $hold;
            });
        };

        // Try Redis lock if cache store supports it, otherwise run directly in DB transaction
        try {
            $lock = Cache::lock($lockKey, $lockTtl);
            return $lock->block(5, $callback);
        } catch (\BadMethodCallException | \Throwable $e) {
            // Driver does not support atomic locks or redis timeout; fallback to safe DB pessimistic lock
            return $callback();
        }
    }

    /**
     * Release an active hold and return seats to available inventory.
     */
    public function releaseHold(string|int $holdIdentifier, ?string $reason = 'Manual release', ?int $actorId = null): FlightHold
    {
        return DB::transaction(function () use ($holdIdentifier, $reason, $actorId) {
            $hold = FlightHold::where('id', $holdIdentifier)
                ->orWhere('hold_id', $holdIdentifier)
                ->lockForUpdate()
                ->firstOrFail();

            if ($hold->status !== HoldStatus::ACTIVE) {
                return $hold;
            }

            // Release balance in allocation engine
            if ($hold->reservation_id && $hold->reservation) {
                try {
                    $this->allocationEngine->releaseReservation(
                        reservation: $hold->reservation,
                        reason:      $reason ?? 'Hold released',
                        actorId:     $actorId,
                    );
                } catch (\Throwable $e) {
                    Log::warning('FlightHoldService: reservation release warning', ['error' => $e->getMessage()]);
                }
            } elseif ($hold->flight_inventory_id && $hold->flightInventory) {
                $hold->flightInventory->increment('available_seats', $hold->quantity);
            }

            $hold->update([
                'status' => HoldStatus::RELEASED,
            ]);

            return $hold->refresh();
        });
    }

    /**
     * Auto-expire all active holds whose TTL has expired.
     */
    public function expireActiveHolds(): int
    {
        $expiredHolds = FlightHold::expired()->get();
        $count = 0;

        foreach ($expiredHolds as $hold) {
            try {
                $this->releaseHold($hold->id, 'Automatic TTL Expiry');
                $hold->update(['status' => HoldStatus::EXPIRED]);
                event(new \Modules\Flight\Events\FlightHoldExpired($hold));
                $count++;
            } catch (\Throwable $e) {
                Log::error('FlightHoldService: failed to expire hold', [
                    'hold_id' => $hold->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Create an immutable FareQuote.
     */
    public function createQuote(array $input, ?Tenant $tenant = null, ?User $user = null): FlightFareQuote
    {
        $idempotencyKey = $input['idempotency_key'] ?? null;
        if ($idempotencyKey) {
            $existingQuote = FlightFareQuote::where('idempotency_key', $idempotencyKey)
                ->where('status', 'ACTIVE')
                ->where('expires_at', '>', Carbon::now())
                ->first();

            if ($existingQuote) {
                return $existingQuote;
            }
        }

        $holdId = $input['hold_id'] ?? null;
        $hold = null;
        if ($holdId) {
            $hold = FlightHold::where('id', $holdId)->orWhere('hold_id', $holdId)->first();
        }

        $fareId = $input['fare_id'] ?? ($hold?->flight_fare_id ?? null);
        $fare = FlightFare::with(['fareFamily', 'flightInventory.airline', 'flightInventory.originAirport', 'flightInventory.destinationAirport'])
            ->find($fareId) ?? FlightFare::with(['fareFamily', 'flightInventory.airline', 'flightInventory.originAirport', 'flightInventory.destinationAirport'])->first();

        $inventory = $fare->flightInventory;
        $quantity = max(1, (int) ($input['quantity'] ?? ($hold?->quantity ?? 1)));

        // Compute pricing
        $priceCheck = $this->revalidatePrice([
            'fare_id'        => $fare->id,
            'expected_price' => $input['expected_price'] ?? null,
            'quantity'       => $quantity,
        ], $tenant);

        if (! $priceCheck['available']) {
            throw new RuntimeException($priceCheck['message'] ?? 'Fare is no longer available for quotation.');
        }

        $quoteTtl = (int) config('flight.quote_ttl', 900);
        $expiresAt = $hold?->expires_at ?? Carbon::now()->addSeconds($quoteTtl);

        $selectedItinerary = [
            'flight_number'   => $inventory?->flight_number,
            'airline'         => [
                'code' => $inventory?->airline?->iata_code,
                'name' => $inventory?->airline?->name,
            ],
            'origin'          => [
                'code' => $inventory?->originAirport?->iata_code,
                'name' => $inventory?->originAirport?->name,
                'city' => $inventory?->originAirport?->city,
            ],
            'destination'     => [
                'code' => $inventory?->destinationAirport?->iata_code,
                'name' => $inventory?->destinationAirport?->name,
                'city' => $inventory?->destinationAirport?->city,
            ],
            'departure_time'  => $inventory?->departure_time,
            'arrival_time'    => $inventory?->arrival_time,
            'departure_date'  => $input['departure_date'] ?? null,
            'departure'       => [
                'date' => $input['departure_date'] ?? null,
                'time' => $inventory?->departure_time,
            ],
            'cabin_class'     => $fare->cabin_class,
            'quantity'        => $quantity,
        ];

        $fareDetails = [
            'fare_id'       => $fare->id,
            'fare_code'     => $fare->fare_code,
            'fare_family'   => $priceCheck['fare_family'],
            'rules'         => $priceCheck['rules'],
        ];

        $quote = FlightFareQuote::create([
            'quote_id'            => 'QUOTE-' . strtoupper(Str::random(10)),
            'hold_id'             => $hold?->id,
            'hold_reference'      => $hold?->hold_id,
            'tenant_id'           => $tenant?->id ?? $fare->tenant_id,
            'user_id'             => $user?->id,
            'supplier_code'       => $fare->supplier_code ?? 'INTERNAL',
            'flight_inventory_id' => $inventory?->id,
            'flight_fare_id'      => $fare->id,
            'currency'            => $fare->currency ?? 'INR',
            'selected_itinerary'  => $selectedItinerary,
            'fare_details'        => $fareDetails,
            'pricing_breakdown'   => $priceCheck['pricing_breakdown'],
            'customer_price'      => $priceCheck['total_price'],
            'search_context'      => $input['search_context'] ?? null,
            'expires_at'          => $expiresAt,
            'status'              => 'ACTIVE',
            'idempotency_key'     => $idempotencyKey,
        ]);

        return $quote;
    }

    /**
     * Retrieve a quote and verify its active status.
     */
    public function getQuote(string $quoteIdentifier): FlightFareQuote
    {
        $quote = FlightFareQuote::where('quote_id', $quoteIdentifier)
            ->orWhere('id', $quoteIdentifier)
            ->firstOrFail();

        if ($quote->status === 'ACTIVE' && $quote->expires_at->isPast()) {
            $quote->update(['status' => 'EXPIRED']);
        }

        return $quote;
    }
}
