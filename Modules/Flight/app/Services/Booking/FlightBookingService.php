<?php

namespace Modules\Flight\Services\Booking;

use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Flight\Enums\BookingStatus;
use Modules\Flight\Enums\HoldStatus;
use Modules\Flight\Enums\PassengerType;
use Modules\Flight\Enums\PaymentStatus;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightBookingPassenger;
use Modules\Flight\Models\FlightFareQuote;
use Modules\Flight\Models\FlightHold;
use Modules\Flight\Models\FlightInventory;
use Modules\Flight\Models\FlightRoute;
use Modules\Flight\Services\Hold\FlightHoldService;
use Modules\Flight\Services\Ticketing\FlightTicketingService;
use Modules\Saas\Models\Tenant;
use Modules\User\Models\User;

class FlightBookingService
{
    public function __construct(
        protected FlightHoldService $holdService,
        protected FlightTicketingService $ticketingService
    ) {}

    /**
     * Create a new booking from an existing immutable quote and passenger details.
     */
    public function createBooking(array $input, ?Tenant $tenant = null, ?User $user = null): FlightBooking
    {
        // 1. Idempotency Check
        if (! empty($input['idempotency_key'])) {
            $existing = FlightBooking::where('idempotency_key', $input['idempotency_key'])
                ->forTenant($tenant?->id)
                ->with(['passengers', 'quote', 'hold'])
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        // 2. Fetch and Validate Fare Quote
        $quoteIdentifier = $input['quote_id'] ?? $input['quote_reference'] ?? null;
        if (empty($quoteIdentifier)) {
            throw new DomainException('Quote reference (quote_id) is required to create a booking.');
        }

        $quote = FlightFareQuote::where('quote_id', $quoteIdentifier)
            ->orWhere('id', is_numeric($quoteIdentifier) ? (int) $quoteIdentifier : 0)
            ->first();

        $requestedFareId = $input['flight_fare_id'] ?? null;
        $validFareId = is_numeric($requestedFareId)
            ? \Modules\Flight\Models\FlightFare::query()->whereKey((int) $requestedFareId)->value('id')
            : null;
        $requestedInventoryId = $input['flight_inventory_id'] ?? null;
        $validInventoryId = is_numeric($requestedInventoryId)
            ? \Modules\Flight\Models\FlightInventory::query()->whereKey((int) $requestedInventoryId)->value('id')
            : null;
        if (! $quote) {
            // Resilient fallback for client-generated quotes or custom route schedules
            $totalAmount = (float) ($input['total_amount'] ?? 5000.00);

            $quote = FlightFareQuote::create([
                'quote_id'            => (string) $quoteIdentifier,
                'tenant_id'           => $tenant?->id ?? 1,
                'user_id'             => $user?->id,
                'supplier_code'       => 'INTERNAL',
                'flight_inventory_id' => $validInventoryId,
                'flight_fare_id'      => $validFareId,
                'currency'            => $input['currency'] ?? 'INR',
                'selected_itinerary'  => [
                    'pnr'        => $input['pnr'] ?? null,
                    'pnr_number' => $input['pnr'] ?? null,
                ],
                'fare_details'        => [
                    'fare_family' => 'STANDARD',
                ],
                'pricing_breakdown'   => [
                    'base_fare'      => $totalAmount,
                    'customer_price' => $totalAmount,
                ],
                'customer_price'      => $totalAmount,
                'expires_at'          => Carbon::now()->addHours(2),
                'status'              => 'ACTIVE',
            ]);
        }

        if ($quote->flight_fare_id !== null && ! \Modules\Flight\Models\FlightFare::query()->whereKey($quote->flight_fare_id)->exists()) {
            $quote->flight_fare_id = null;
            $quote->save();
        }

        if ($quote->flight_inventory_id !== null && ! \Modules\Flight\Models\FlightInventory::query()->whereKey($quote->flight_inventory_id)->exists()) {
            $quote->flight_inventory_id = null;
            $quote->save();
        }

        if ($quote->isExpired()) {
            $quote->expires_at = Carbon::now()->addHours(2);
            $quote->status = 'ACTIVE';
            $quote->save();
        }

        if ($quote->status !== 'ACTIVE') {
            $quote->status = 'ACTIVE';
            $quote->save();
        }

        // Flight vouchers are revalidated against the server-owned quote at
        // booking creation. The browser never supplies a discount amount.
        $flightOffer = null;
        $flightOfferService = app(FlightOfferService::class);
        $discountId = data_get($input, 'meta.flight_discount_id');
        $automaticDiscount = filled($discountId)
            ? $flightOfferService->validateDiscount($quote, (int) $discountId)
            : null;
        $offerCode = data_get($input, 'meta.flight_offer_code');
        if (filled($offerCode)) {
            $flightOffer = $flightOfferService->validateCode($quote->quote_id, (string) $offerCode, filled($discountId) ? (int) $discountId : null);
        }

        // 3. Validate Linked Hold (if present)
        $hold = null;
        if ($quote->hold_id || $quote->hold_reference) {
            $hold = FlightHold::where('id', $quote->hold_id)
                ->orWhere('hold_id', $quote->hold_reference)
                ->first();

            if ($hold && $hold->isExpired()) {
                throw new DomainException('The temporary seat hold linked to this quote has expired.');
            }

            if ($hold && $hold->status !== HoldStatus::ACTIVE) {
                throw new DomainException("Seat hold is not active (current status: {$hold->status->value}).");
            }
        }

        // 4. Validate Passenger Rules
        $passengersData = $input['passengers'] ?? [];
        if (empty($passengersData) || ! is_array($passengersData)) {
            throw new DomainException('At least one passenger is required.');
        }

        $adultCount = 0;
        $childCount = 0;
        $infantCount = 0;
        $isInternational = (bool) ($input['is_international'] ?? false);

        foreach ($passengersData as $idx => $pax) {
            $typeStr = strtoupper($pax['passenger_type'] ?? 'ADULT');
            $type = PassengerType::tryFrom($typeStr) ?? PassengerType::ADULT;

            if ($type === PassengerType::ADULT) {
                $adultCount++;
            } elseif ($type === PassengerType::CHILD) {
                $childCount++;
            } elseif ($type === PassengerType::INFANT) {
                $infantCount++;
            }

            // DOB and age validation
            if (! empty($pax['date_of_birth'])) {
                $dob = Carbon::parse($pax['date_of_birth']);
                $age = $dob->age;

                if ($type === PassengerType::CHILD && ($age < 2 || $age >= 12)) {
                    throw new DomainException("Passenger #" . ($idx + 1) . " is marked as CHILD but age ({$age}) is outside the 2–11 range.");
                }

                if ($type === PassengerType::INFANT && $age >= 2) {
                    throw new DomainException("Passenger #" . ($idx + 1) . " is marked as INFANT but age ({$age}) is 2 or older.");
                }
            }

            // International Travel Passport Validation
            $paxInternational = $isInternational || ! empty($pax['is_international']);
            if ($paxInternational) {
                if (empty($pax['passport_number'])) {
                    throw new DomainException("Passport number is required for international passenger #" . ($idx + 1) . ".");
                }

                if (! empty($pax['passport_expiry'])) {
                    $expiry = Carbon::parse($pax['passport_expiry']);
                    if ($expiry->isPast()) {
                        throw new DomainException("Passport for passenger #" . ($idx + 1) . " has expired ({$pax['passport_expiry']}).");
                    }
                }
            }
        }

        if ($adultCount < 1) {
            throw new DomainException('At least one adult passenger is required.');
        }

        if ($infantCount > $adultCount) {
            throw new DomainException("Number of infants ({$infantCount}) cannot exceed number of accompanying adults ({$adultCount}).");
        }

        // 5. Tenant and User Scoping
        $tenantId = $tenant?->id ?? $quote->tenant_id;
        $userId = $user?->id ?? $quote->user_id;

        // 6. Primary Contact Details
        $firstPax = $passengersData[0];
        $contactName = $input['contact_name'] ?? trim(($firstPax['first_name'] ?? '') . ' ' . ($firstPax['last_name'] ?? ''));
        $contactEmail = $input['contact_email'] ?? ($firstPax['contact_email'] ?? null);
        $contactPhone = $input['contact_phone'] ?? ($firstPax['contact_phone'] ?? null);

        if (empty($contactEmail)) {
            throw new DomainException('Primary contact email is required.');
        }

        // 7. Atomic Booking Creation
        return DB::transaction(function () use (
            $input, $tenantId, $userId, $quote, $hold, $passengersData,
            $adultCount, $childCount, $infantCount, $contactName, $contactEmail, $contactPhone, $isInternational, $flightOffer, $automaticDiscount
        ) {
            $pricingBreakdown = $quote->pricing_breakdown ?? [];
            $bookingTotal = (float) $quote->customer_price;
            $bookingMeta = $input['meta'] ?? [];
            unset($bookingMeta['flight_offer'], $bookingMeta['flight_offer_code']);
            if ($automaticDiscount) {
                $bookingTotal = max(0, $bookingTotal - (float) $automaticDiscount['discount']);
                $pricingBreakdown['flight_automatic_discount'] = $automaticDiscount;
                $pricingBreakdown['discount'] = (float) ($pricingBreakdown['discount'] ?? 0) + (float) $automaticDiscount['discount'];
                $bookingMeta['flight_automatic_discount'] = $automaticDiscount;
            }
            if ($flightOffer) {
                $bookingTotal = max(0, $bookingTotal - (float) $flightOffer['discount']);
                $pricingBreakdown['flight_offer'] = $flightOffer;
                $pricingBreakdown['discount'] = (float) ($pricingBreakdown['discount'] ?? 0) + (float) $flightOffer['discount'];
                $bookingMeta['flight_offer'] = $flightOffer;
                $bookingMeta['flight_offer_code'] = $flightOffer['code'];
            }

            $bookingPnr = ! empty($input['pnr'])
                ? strtoupper($input['pnr'])
                : ($quote->selected_itinerary['pnr'] ?? $quote->selected_itinerary['pnr_number'] ?? null);
            $bookingPnr = $bookingPnr ?: $this->resolveRoutePnr($quote);
            $supplierCode = strtoupper((string) ($quote->supplier_code ?? 'INTERNAL'));
            // Admin-managed route fares are stored with supplier_code=DIRECT.
            // DIRECT is not a live ticketing integration here; letting it fall
            // through to the mock supplier would create a fake airline PNR.
            $usesInternalInventory = $quote->flight_inventory_id !== null
                && in_array($supplierCode, ['INTERNAL', 'DIRECT'], true);
            $manualPnrRequired = $usesInternalInventory && empty($bookingPnr);

            $booking = FlightBooking::create([
                'tenant_id'           => $tenantId,
                'user_id'             => $userId,
                'flight_customer_id'  => $input['flight_customer_id'] ?? null,
                'quote_id'            => $quote->id,
                'quote_reference'     => $quote->quote_id,
                'hold_id'             => $hold?->id ?? $quote->hold_id,
                'hold_reference'      => $hold?->hold_id ?? $quote->hold_reference,
                'pnr'                 => $bookingPnr,
                'supplier_pnr'        => $bookingPnr,
                'manual_pnr_required' => $manualPnrRequired,
                'flight_inventory_id' => $quote->flight_inventory_id,
                'flight_fare_id'      => $quote->flight_fare_id,
                'supplier_code'       => $quote->supplier_code ?? 'INTERNAL',
                'itinerary'           => $quote->selected_itinerary,
                'fare_snapshot'       => $quote->fare_details,
                'pricing_breakdown'   => $pricingBreakdown,
                'total_amount'        => round($bookingTotal, 2),
                'currency'            => $quote->currency ?? 'INR',
                'booking_status'      => BookingStatus::PAYMENT_PENDING,
                'payment_status'      => PaymentStatus::PENDING,
                'pax_count_adult'     => $adultCount,
                'pax_count_child'     => $childCount,
                'pax_count_infant'    => $infantCount,
                'contact_name'        => $contactName,
                'contact_email'       => $contactEmail,
                'contact_phone'       => $contactPhone,
                'idempotency_key'     => $input['idempotency_key'] ?? null,
                'meta'                => $bookingMeta ?: null,
            ]);

            // Create Passenger Records
            foreach ($passengersData as $pax) {
                $typeStr = strtoupper($pax['passenger_type'] ?? 'ADULT');
                $type = PassengerType::tryFrom($typeStr) ?? PassengerType::ADULT;

                FlightBookingPassenger::create([
                    'booking_id'            => $booking->id,
                    'tenant_id'             => $tenantId,
                    'passenger_type'        => $type,
                    'title'                 => $pax['title'] ?? null,
                    'first_name'            => $pax['first_name'],
                    'last_name'             => $pax['last_name'],
                    'date_of_birth'         => ! empty($pax['date_of_birth']) ? $pax['date_of_birth'] : null,
                    'gender'                => $pax['gender'] ?? null,
                    'nationality'           => $pax['nationality'] ?? 'IN',
                    'is_international'      => (bool) ($pax['is_international'] ?? $isInternational),
                    'passport_number'       => $pax['passport_number'] ?? null,
                    'passport_expiry'       => ! empty($pax['passport_expiry']) ? $pax['passport_expiry'] : null,
                    'passport_country'      => $pax['passport_country'] ?? null,
                    'seat_preference'       => $pax['seat_preference'] ?? null,
                    'meal_preference'       => $pax['meal_preference'] ?? null,
                    'frequent_flyer_number' => $pax['frequent_flyer_number'] ?? null,
                    'contact_email'         => $pax['contact_email'] ?? null,
                    'contact_phone'         => $pax['contact_phone'] ?? null,
                    'meta'                  => $pax['meta'] ?? null,
                ]);
            }

            // Track coupon and automatic discount usage only after the booking
            // has been created successfully, in the same transaction.
            app(FlightOfferService::class)->redeemForBooking($booking);

            $loadedBooking = $booking->load(['passengers', 'quote', 'hold', 'flightInventory']);
            event(new \Modules\Flight\Events\FlightBookingCreated($loadedBooking));

            return $loadedBooking;
        });
    }

    /**
     * Retrieve a booking by its reference or numeric ID, scoped to tenant.
     */
    public function getBooking(string|int $identifier, ?Tenant $tenant = null): FlightBooking
    {
        $query = FlightBooking::with([
            'passengers',
            'quote',
            'hold',
            'flightInventory.originAirport',
            'flightInventory.destinationAirport',
            'flightInventory.airline',
            'flightFare',
        ]);

        if (is_numeric($identifier)) {
            $query->where(function (Builder $q) use ($identifier) {
                $q->where('id', $identifier)
                  ->orWhere('booking_reference', (string) $identifier);
            });
        } else {
            $query->where('booking_reference', $identifier);
        }

        if ($tenant) {
            $query->forTenant($tenant->id);
        }

        $booking = $query->first();

        if (! $booking) {
            throw new DomainException("Booking [{$identifier}] not found.");
        }

        return $booking;
    }

    /**
     * List bookings with filtering, sorting, pagination, and tenant isolation.
     */
    public function listBookings(array $filters = [], ?Tenant $tenant = null, int $perPage = 15): LengthAwarePaginator
    {
        $query = FlightBooking::with(['passengers', 'flightInventory.originAirport', 'flightInventory.destinationAirport', 'flightInventory.airline', 'flightFare'])
            ->latest('id');

        if ($tenant) {
            $query->forTenant($tenant->id);
        }

        if (! empty($filters['status'])) {
            $query->byStatus($filters['status']);
        }

        if (! empty($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        }

        if (! empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function (Builder $q) use ($search) {
                $q->where('booking_reference', 'like', $search)
                  ->orWhere('contact_name', 'like', $search)
                  ->orWhere('contact_email', 'like', $search)
                  ->orWhere('contact_phone', 'like', $search)
                  ->orWhereHas('passengers', function (Builder $pq) use ($search) {
                      $pq->where('first_name', 'like', $search)
                         ->orWhere('last_name', 'like', $search);
                  });
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * Confirm a booking after payment is processed. Converts hold to CONVERTED.
     */
    public function confirmBooking(string|int $identifier, array $paymentData = [], ?Tenant $tenant = null): FlightBooking
    {
        $booking = $this->getBooking($identifier, $tenant);

        // State machine check
        $targetStatus = $booking->manual_pnr_required && empty($booking->pnr)
            ? BookingStatus::PNR_PENDING
            : BookingStatus::CONFIRMED;
        $booking->transitionTo($targetStatus);

        $booking->payment_status = PaymentStatus::PAID;
        $booking->payment_reference = $paymentData['payment_reference'] ?? ('PAY-' . strtoupper(Str::random(10)));
        $booking->save();

        // A linked hold already decremented available seats when it was created.
        // Do not deduct the same passengers a second time on confirmation.
        $hasSeatHold = (bool) ($booking->hold_id || $booking->hold_reference);
        if (! $hasSeatHold && $booking->flight_inventory_id) {
            $inv = \Modules\Flight\Models\FlightInventory::find($booking->flight_inventory_id);
            if ($inv && $inv->available_seats > 0) {
                $paxCount = ($booking->pax_count_adult ?? 1) + ($booking->pax_count_child ?? 0);
                $inv->decrement('available_seats', min($paxCount, $inv->available_seats));
            }
        }

        // Convert temporary hold to permanent booking conversion
        if ($booking->hold_id || $booking->hold_reference) {
            $hold = FlightHold::where('id', $booking->hold_id)
                ->orWhere('hold_id', $booking->hold_reference)
                ->first();

            if ($hold && $hold->status === HoldStatus::ACTIVE) {
                $hold->status = HoldStatus::CONVERTED;
                $hold->save();
            }
        }

        return $booking->fresh(['passengers', 'quote', 'hold']);
    }

    /** Assign an airline PNR to a paid internal booking awaiting manual PNR entry. */
    public function assignPnr(string|int $identifier, string $pnr, ?Tenant $tenant = null): FlightBooking
    {
        return DB::transaction(function () use ($identifier, $pnr, $tenant): FlightBooking {
            $query = FlightBooking::query()
                ->where(function (Builder $builder) use ($identifier): void {
                    $builder->where('booking_reference', (string) $identifier);
                    if (is_numeric($identifier)) {
                        $builder->orWhere('id', (int) $identifier);
                    }
                });

            if ($tenant) {
                $query->where('tenant_id', $tenant->id);
            }

            $booking = $query->lockForUpdate()->firstOrFail();
            if (! $booking->manual_pnr_required || $booking->payment_status !== PaymentStatus::PAID) {
                throw new DomainException('A PNR can only be assigned to a paid booking awaiting manual PNR entry.');
            }
            if ($booking->booking_status !== BookingStatus::PNR_PENDING) {
                throw new DomainException('This booking is no longer waiting for a PNR.');
            }

            $booking->pnr = strtoupper(trim($pnr));
            $booking->supplier_pnr = $booking->pnr;
            $itinerary = (array) ($booking->itinerary ?? []);
            $itinerary['pnr'] = $booking->pnr;
            $itinerary['pnr_number'] = $booking->pnr;
            $booking->itinerary = $itinerary;
            $booking->transitionTo(BookingStatus::BOOKED);
            $pnrBooking = $booking->fresh();
            DB::afterCommit(fn () => event(new \Modules\Flight\Events\FlightPnrGenerated($pnrBooking, $pnrBooking->pnr)));

            return $booking->fresh(['passengers', 'quote', 'hold', 'flightInventory']);
        });
    }

    /** Assign a route-level PNR to already-paid bookings for that exact flight date. */
    public function assignRoutePnr(FlightRoute $route): int
    {
        $pnr = strtoupper(trim((string) $route->pnr_number));
        $departureDate = $route->flight_date?->toDateString();
        if ($pnr === '' || ! $departureDate) {
            return 0;
        }

        $inventoryIds = FlightInventory::query()
            ->where('tenant_id', $route->tenant_id)
            ->where('flight_number', $route->flight_number)
            ->where('origin_airport_id', $route->origin_airport_id)
            ->where('destination_airport_id', $route->destination_airport_id)
            ->when($route->flight_time, fn (Builder $query) => $query->whereTime('departure_time', $route->flight_time))
            ->pluck('id');

        if ($inventoryIds->isEmpty()) {
            return 0;
        }

        $bookings = FlightBooking::query()
            ->where('tenant_id', $route->tenant_id)
            ->whereIn('flight_inventory_id', $inventoryIds)
            ->where('manual_pnr_required', true)
            ->where('payment_status', PaymentStatus::PAID)
            ->where('booking_status', BookingStatus::PNR_PENDING)
            ->get();

        $updated = 0;
        foreach ($bookings as $booking) {
            $itinerary = (array) ($booking->itinerary ?? []);
            $bookingDate = data_get($itinerary, 'departure.date')
                ?? data_get($itinerary, 'departure_date');
            if (! $bookingDate || Carbon::parse($bookingDate)->toDateString() !== $departureDate) {
                continue;
            }

            $this->assignPnr($booking->booking_reference, $pnr);
            $booking->refresh();
            try {
                $this->ticketingService->issueTicket($booking);
            } catch (\Throwable $exception) {
                // Keep the administrator's PNR update even if ticket issuance
                // needs a retry; do not roll the booking back to PNR_PENDING.
                Log::warning('Automatic ticket issuance failed after route PNR assignment.', [
                    'booking_reference' => $booking->booking_reference,
                    'error' => $exception->getMessage(),
                ]);
                $booking->refresh()->update([
                    'ticket_status' => \Modules\Flight\Enums\TicketStatus::FAILED,
                    'ticketing_error' => $exception->getMessage(),
                ]);
            }
            $updated++;
        }

        return $updated;
    }

    private function resolveRoutePnr(FlightFareQuote $quote): ?string
    {
        if (! $quote->flight_inventory_id) {
            return null;
        }

        $itinerary = (array) ($quote->selected_itinerary ?? []);
        $departureDate = data_get($itinerary, 'departure.date')
            ?? data_get($itinerary, 'departure_date');
        if (! $departureDate) {
            return null;
        }

        $inventory = FlightInventory::query()->find($quote->flight_inventory_id);
        if (! $inventory) {
            return null;
        }

        $route = FlightRoute::query()
            ->where('tenant_id', $quote->tenant_id)
            ->where('flight_number', $inventory->flight_number)
            ->where('origin_airport_id', $inventory->origin_airport_id)
            ->where('destination_airport_id', $inventory->destination_airport_id)
            ->whereDate('flight_date', Carbon::parse($departureDate)->toDateString())
            ->when($inventory->departure_time, fn (Builder $query) => $query->whereTime('flight_time', $inventory->departure_time))
            ->first();

        return filled($route?->pnr_number) ? strtoupper(trim($route->pnr_number)) : null;
    }

    /**
     * Cancel an active booking and release any held seats.
     */
    public function cancelBooking(string|int $identifier, string $reason = 'Customer cancellation', ?Tenant $tenant = null): FlightBooking
    {
        $booking = $this->getBooking($identifier, $tenant);

        // State machine check: cannot cancel from CANCELLED, FAILED, COMPLETED
        $booking->transitionTo(BookingStatus::CANCELLED, $reason);

        if ($booking->payment_status === PaymentStatus::PAID) {
            $booking->payment_status = PaymentStatus::REFUNDED;
        }
        $booking->save();

        // Release linked hold if still active
        if ($booking->hold_id || $booking->hold_reference) {
            try {
                $this->holdService->releaseHold(
                    $booking->hold_id ?? $booking->hold_reference,
                    "Booking {$booking->booking_reference} cancelled: {$reason}"
                );
            } catch (\Throwable $e) {
                // Ignore if already converted or released
            }
        }

        return $booking->fresh(['passengers', 'quote', 'hold']);
    }

    /**
     * Update details of an existing passenger on an active booking.
     */
    public function updatePassenger(
        string|int $bookingIdentifier,
        int $passengerId,
        array $data,
        ?Tenant $tenant = null
    ): FlightBookingPassenger {
        $booking = $this->getBooking($bookingIdentifier, $tenant);

        if ($booking->booking_status->isTerminal()) {
            throw new DomainException("Cannot modify passengers on a booking in status: {$booking->booking_status->value}.");
        }

        $passenger = FlightBookingPassenger::where('id', $passengerId)
            ->where('booking_id', $booking->id)
            ->first();

        if (! $passenger) {
            throw new DomainException("Passenger #{$passengerId} not found on booking {$booking->booking_reference}.");
        }

        $fillable = [
            'title', 'first_name', 'last_name', 'date_of_birth',
            'gender', 'nationality', 'is_international',
            'passport_number', 'passport_expiry', 'passport_country',
            'seat_preference', 'meal_preference', 'frequent_flyer_number',
            'contact_email', 'contact_phone',
        ];

        foreach ($fillable as $field) {
            if (array_key_exists($field, $data)) {
                $passenger->{$field} = $data[$field];
            }
        }

        $passenger->save();

        return $passenger;
    }

    /**
     * Get light status payload for tracking.
     */
    public function getBookingStatus(string|int $identifier, ?Tenant $tenant = null): array
    {
        $booking = $this->getBooking($identifier, $tenant);

        return [
            'booking_reference' => $booking->booking_reference,
            'booking_status'    => $booking->booking_status->value,
            'status_label'      => $booking->booking_status->label(),
            'manual_pnr_required' => $booking->manual_pnr_required,
            'payment_status'    => $booking->payment_status->value,
            'payment_label'     => $booking->payment_status->label(),
            'total_amount'      => (float) $booking->total_amount,
            'currency'          => $booking->currency,
            'passenger_count'   => $booking->passengers()->count(),
            'contact_email'     => $booking->contact_email,
            'contact_name'      => $booking->contact_name,
            'created_at'        => $booking->created_at?->toIso8601String(),
            'confirmed_at'      => $booking->confirmed_at?->toIso8601String(),
            'cancelled_at'      => $booking->cancelled_at?->toIso8601String(),
        ];
    }
}
