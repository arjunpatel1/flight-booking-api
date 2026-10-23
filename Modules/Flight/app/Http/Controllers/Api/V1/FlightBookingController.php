<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Services\Booking\FlightBookingService;
use Modules\Flight\Models\FlightCustomer;
use Modules\Saas\Models\Tenant;
use Modules\Support\ApiResponse;
use Modules\User\Models\User;

class FlightBookingController extends Controller
{
    public function __construct(
        protected FlightBookingService $bookingService
    ) {}

    protected function resolveTenant(Request $request): ?Tenant
    {
        $tenantId = $request->user()?->tenant_id ?? auth()->user()?->tenant_id;
        if (! $tenantId && $request->hasHeader('X-Tenant-ID')) {
            $tenantId = (int) $request->header('X-Tenant-ID');
        }
        if (! $tenantId && $request->filled('tenant_id')) {
            $tenantId = (int) $request->input('tenant_id');
        }

        if ($tenantId) {
            return Tenant::find($tenantId);
        }

        return null;
    }

    /**
     * Create a new flight booking from a valid fare quote.
     * POST /api/v1/flight/bookings
     */
    public function createBooking(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quote_id'                             => 'required_without:quote_reference|string',
            'pnr'                                  => 'nullable|string|max:50',
            'total_amount'                         => 'nullable|numeric',
            'currency'                             => 'nullable|string|max:10',
            'flight_inventory_id'                  => 'nullable',
            'flight_fare_id'                       => 'nullable',
            'quote_reference'                      => 'nullable|string',
            'passengers'                           => 'required|array|min:1',
            'passengers.*.first_name'              => 'required|string|max:100',
            'passengers.*.last_name'               => 'required|string|max:100',
            'passengers.*.title'                   => 'nullable|string|max:20',
            'passengers.*.passenger_type'          => 'nullable|string|in:ADULT,CHILD,INFANT,adult,child,infant',
            'passengers.*.date_of_birth'           => 'nullable|date',
            'passengers.*.gender'                  => 'nullable|string',
            'passengers.*.nationality'             => 'nullable|string|max:10',
            'passengers.*.passport_number'         => 'nullable|string|max:50',
            'passengers.*.passport_expiry'         => 'nullable|date',
            'passengers.*.passport_country'        => 'nullable|string|max:10',
            'passengers.*.is_international'        => 'nullable|boolean',
            'passengers.*.seat_preference'         => 'nullable|string|max:50',
            'passengers.*.meal_preference'         => 'nullable|string|max:50',
            'passengers.*.frequent_flyer_number'   => 'nullable|string|max:50',
            'passengers.*.contact_email'           => 'nullable|email|max:150',
            'passengers.*.contact_phone'           => 'nullable|string|max:50',
            'contact_name'                         => 'nullable|string|max:150',
            'contact_email'                        => 'nullable|email|max:150',
            'contact_phone'                        => 'nullable|string|max:50',
            'is_international'                     => 'nullable|boolean',
            'idempotency_key'                      => 'nullable|string|max:100',
            'meta'                                 => 'nullable|array',
        ]);

        try {
            $tenant = $this->resolveTenant($request);
            $customer = $request->user('sanctum');
            if ($customer instanceof FlightCustomer) {
                // Derive the account link from its verified bearer token, not client input.
                $validated['flight_customer_id'] = $customer->id;
            }
            $authenticatedUser = $request->user();
            $user = $authenticatedUser instanceof User ? $authenticatedUser : null;

            $booking = $this->bookingService->createBooking($validated, $tenant, $user);

            return ApiResponse::created([
                'booking_reference' => $booking->booking_reference,
                'id'                => $booking->id,
                'booking_status'    => $booking->booking_status->value,
                'status_label'      => $booking->booking_status->label(),
                'payment_status'    => $booking->payment_status->value,
                'payment_label'     => $booking->payment_status->label(),
                'manual_pnr_required' => $booking->manual_pnr_required,
                'total_amount'      => (float) $booking->total_amount,
                'currency'          => $booking->currency,
                'contact_name'      => $booking->contact_name,
                'contact_email'     => $booking->contact_email,
                'contact_phone'     => $booking->contact_phone,
                'pax_count_adult'   => $booking->pax_count_adult,
                'pax_count_child'   => $booking->pax_count_child,
                'pax_count_infant'  => $booking->pax_count_infant,
                'quote_reference'   => $booking->quote_reference,
                'hold_reference'    => $booking->hold_reference,
                'itinerary'         => $booking->itinerary,
                'fare_snapshot'     => $booking->fare_snapshot,
                'pricing_breakdown' => $booking->pricing_breakdown,
                'passengers'        => $booking->passengers->map(fn ($p) => [
                    'id'               => $p->id,
                    'passenger_type'   => $p->passenger_type->value,
                    'type_label'       => $p->passenger_type->label(),
                    'title'            => $p->title,
                    'first_name'       => $p->first_name,
                    'last_name'        => $p->last_name,
                    'full_name'        => $p->full_name,
                    'date_of_birth'    => $p->date_of_birth?->format('Y-m-d'),
                    'gender'           => $p->gender,
                    'nationality'      => $p->nationality,
                    'is_international' => $p->is_international,
                    'passport_number'  => $p->passport_number,
                    'passport_expiry'  => $p->passport_expiry?->format('Y-m-d'),
                    'seat_preference'  => $p->seat_preference,
                    'meal_preference'  => $p->meal_preference,
                ]),
                'created_at'        => $booking->created_at?->toIso8601String(),
            ]);;
        } catch (DomainException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            report($e);
            $errorBody = null;
            if (app()->environment('local')) {
                $errorBody = [
                    'type' => class_basename($e),
                    'database_code' => $e instanceof \Illuminate\Database\QueryException ? ($e->errorInfo[1] ?? null) : null,
                    'database_constraint' => ($e instanceof \Illuminate\Database\QueryException && preg_match('~CONSTRAINT `([^`]+)`~', (string) ($e->errorInfo[2] ?? ''), $matches)) ? $matches[1] : null,
                ];
            }
            return ApiResponse::errors($errorBody, 'Unable to create the booking. Please try again.', 500);
        }
    }

    /**
     * Get booking details by reference or numeric ID.
     * GET /api/v1/flight/bookings/{identifier}
     */
    public function getBooking(Request $request, string $identifier): JsonResponse
    {
        try {
            $tenant = $this->resolveTenant($request);
            $booking = $this->bookingService->getBooking($identifier, $tenant);

            return ApiResponse::success([
                'booking_reference' => $booking->booking_reference,
                'id'                => $booking->id,
                'pnr'               => $booking->pnr,
                'tenant_id'         => $booking->tenant_id,
                'manual_pnr_required' => $booking->manual_pnr_required,
                'ticket_status'     => $booking->ticket_status?->value,
                'booking_status'    => $booking->booking_status->value,
                'status_label'      => $booking->booking_status->label(),
                'payment_status'    => $booking->payment_status->value,
                'payment_label'     => $booking->payment_status->label(),
                'payment_reference' => $booking->payment_reference,
                'total_amount'      => (float) $booking->total_amount,
                'currency'          => $booking->currency,
                'contact_name'      => $booking->contact_name,
                'contact_email'     => $booking->contact_email,
                'contact_phone'     => $booking->contact_phone,
                'pax_count_adult'   => $booking->pax_count_adult,
                'pax_count_child'   => $booking->pax_count_child,
                'pax_count_infant'  => $booking->pax_count_infant,
                'quote_reference'   => $booking->quote_reference,
                'hold_reference'    => $booking->hold_reference,
                'itinerary'         => $booking->itinerary,
                'fare_snapshot'     => $booking->fare_snapshot,
                'pricing_breakdown' => $booking->pricing_breakdown,
                'confirmed_at'      => $booking->confirmed_at?->toIso8601String(),
                'cancelled_at'      => $booking->cancelled_at?->toIso8601String(),
                'cancellation_reason' => $booking->cancellation_reason,
                'passengers'        => $booking->passengers->map(fn ($p) => [
                    'id'               => $p->id,
                    'passenger_type'   => $p->passenger_type->value,
                    'type_label'       => $p->passenger_type->label(),
                    'title'            => $p->title,
                    'first_name'       => $p->first_name,
                    'last_name'        => $p->last_name,
                    'full_name'        => $p->full_name,
                    'date_of_birth'    => $p->date_of_birth?->format('Y-m-d'),
                    'gender'           => $p->gender,
                    'nationality'      => $p->nationality,
                    'is_international' => $p->is_international,
                    'passport_number'  => $p->passport_number,
                    'passport_expiry'  => $p->passport_expiry?->format('Y-m-d'),
                    'seat_preference'  => $p->seat_preference,
                    'meal_preference'  => $p->meal_preference,
                    'frequent_flyer_number' => $p->frequent_flyer_number,
                    'contact_email'    => $p->contact_email,
                    'contact_phone'    => $p->contact_phone,
                ]),
                'created_at'        => $booking->created_at?->toIso8601String(),
                'updated_at'        => $booking->updated_at?->toIso8601String(),
            ]);
        } catch (DomainException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 404);
        } catch (\Throwable $e) {
            return ApiResponse::errors(null, $e->getMessage(), 500);
        }
    }

    /**
     * List bookings with search, filter, and tenant isolation.
     * GET /api/v1/flight/bookings
     */
    public function listBookings(Request $request): JsonResponse
    {
        $tenant = $this->resolveTenant($request);
        $filters = $request->only(['status', 'payment_status', 'search']);
        $perPage = (int) $request->input('per_page', 15);

        $paginator = $this->bookingService->listBookings($filters, $tenant, $perPage);

        return ApiResponse::success([
            'data'         => collect($paginator->items())->map(function ($b) {
                $itin = $b->itinerary;
                if (is_string($itin)) {
                    $itin = json_decode($itin, true);
                }

                $originCode = null;
                $destCode = null;
                $flightNumber = null;
                $airlineName = null;

                if (is_array($itin)) {
                    if (isset($itin['origin'])) {
                        $originCode = is_array($itin['origin']) ? ($itin['origin']['code'] ?? $itin['origin']['iata_code'] ?? $itin['origin']['city'] ?? null) : $itin['origin'];
                    }
                    if (isset($itin['destination'])) {
                        $destCode = is_array($itin['destination']) ? ($itin['destination']['code'] ?? $itin['destination']['iata_code'] ?? $itin['destination']['city'] ?? null) : $itin['destination'];
                    }
                    $flightNumber = $itin['flight_number'] ?? null;
                    if (isset($itin['airline'])) {
                        $airlineName = is_array($itin['airline']) ? ($itin['airline']['name'] ?? $itin['airline']['code'] ?? null) : $itin['airline'];
                    }
                }

                if (! $originCode || ! $destCode || ! $flightNumber) {
                    $inv = $b->flightInventory;
                    if ($inv) {
                        if ($inv->relationLoaded('originAirport') && $inv->originAirport) {
                            $originCode = $originCode ?: ($inv->originAirport->iata_code ?? $inv->originAirport->code);
                        }
                        if ($inv->relationLoaded('destinationAirport') && $inv->destinationAirport) {
                            $destCode = $destCode ?: ($inv->destinationAirport->iata_code ?? $inv->destinationAirport->code);
                        }
                        if ($inv->relationLoaded('airline') && $inv->airline) {
                            $airlineName = $airlineName ?: ($inv->airline->name ?? $inv->airline->code);
                        }
                        $flightNumber = $flightNumber ?: $inv->flight_number;
                    }
                }

                return [
                    'booking_reference' => $b->booking_reference,
                    'id'                => $b->id,
                    'pnr'               => $b->pnr,
                    'manual_pnr_required' => $b->manual_pnr_required,
                    'ticket_status'     => $b->ticket_status?->value,
                    'booking_status'    => $b->booking_status->value,
                    'status_label'      => $b->booking_status->label(),
                    'payment_status'    => $b->payment_status->value,
                    'payment_label'     => $b->payment_status->label(),
                    'total_amount'      => (float) $b->total_amount,
                    'currency'          => $b->currency,
                    'contact_name'      => $b->contact_name,
                    'contact_email'     => $b->contact_email,
                    'contact_phone'     => $b->contact_phone,
                    'pax_count'         => $b->passengers->count(),
                    'pax_count_adult'   => $b->pax_count_adult,
                    'pax_count_child'   => $b->pax_count_child,
                    'pax_count_infant'  => $b->pax_count_infant,
                    'itinerary'         => $b->itinerary,
                    'origin'            => $originCode ?: 'DEL',
                    'destination'       => $destCode ?: 'BOM',
                    'flight_number'     => $flightNumber ?: 'AI-Flight',
                    'airline_name'      => $airlineName ?: 'Air India',
                    'confirmed_at'      => $b->confirmed_at?->toIso8601String(),
                    'cancelled_at'      => $b->cancelled_at?->toIso8601String(),
                    'created_at'        => $b->created_at?->toIso8601String(),
                ];
            }),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
        ]);
    }

    /**
     * Lightweight status check for booking reference.
     * GET /api/v1/flight/bookings/{identifier}/status
     */
    public function bookingStatus(Request $request, string $identifier): JsonResponse
    {
        try {
            $tenant = $this->resolveTenant($request);
            $status = $this->bookingService->getBookingStatus($identifier, $tenant);

            return ApiResponse::success($status);
        } catch (DomainException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 404);
        }
    }

    /** Assign the airline PNR to a paid internal booking awaiting manual entry. */
    public function assignPnr(Request $request, string $identifier): JsonResponse
    {
        $validated = $request->validate([
            'pnr' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        try {
            $booking = $this->bookingService->assignPnr(
                $identifier,
                $validated['pnr'],
                $this->resolveTenant($request),
            );

            return ApiResponse::success([
                'booking_reference' => $booking->booking_reference,
                'pnr' => $booking->pnr,
                'booking_status' => $booking->booking_status->value,
                'status_label' => $booking->booking_status->label(),
                'payment_status' => $booking->payment_status->value,
                'ticket_status' => $booking->ticket_status?->value,
                'message' => 'PNR saved and booking marked as booked.',
            ]);
        } catch (DomainException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            report($e);
            return ApiResponse::errors(null, 'Could not save the PNR for this booking.', 500);
        }
    }

    /**
     * Confirm a booking after payment.
     * POST /api/v1/flight/bookings/{identifier}/confirm
     */
    public function confirmBooking(Request $request, string $identifier): JsonResponse
    {
        $validated = $request->validate([
            'payment_reference' => 'nullable|string|max:100',
            'payment_method'    => 'nullable|string|max:50',
        ]);

        try {
            $tenant = $this->resolveTenant($request);
            $booking = $this->bookingService->confirmBooking($identifier, $validated, $tenant);

            return ApiResponse::success([
                'booking_reference' => $booking->booking_reference,
                'booking_status'    => $booking->booking_status->value,
                'payment_status'    => $booking->payment_status->value,
                'payment_reference' => $booking->payment_reference,
                'confirmed_at'      => $booking->confirmed_at?->toIso8601String(),
                'message'           => $booking->booking_status === \Modules\Flight\Enums\BookingStatus::PNR_PENDING
                    ? 'Payment confirmed. The booking is waiting for an administrator to assign its PNR.'
                    : 'Booking confirmed successfully.',
            ]);
        } catch (DomainException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return ApiResponse::errors(null, $e->getMessage(), 500);
        }
    }

    /**
     * Cancel a booking and release any held seats.
     * POST /api/v1/flight/bookings/{identifier}/cancel
     */
    public function cancelBooking(Request $request, string $identifier): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $tenant = $this->resolveTenant($request);
            $reason = $validated['reason'] ?? 'Customer request';
            $booking = $this->bookingService->cancelBooking($identifier, $reason, $tenant);

            return ApiResponse::success([
                'booking_reference'   => $booking->booking_reference,
                'booking_status'      => $booking->booking_status->value,
                'payment_status'      => $booking->payment_status->value,
                'cancellation_reason' => $booking->cancellation_reason,
                'cancelled_at'        => $booking->cancelled_at?->toIso8601String(),
                'message'             => 'Booking cancelled successfully.',
            ]);
        } catch (DomainException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return ApiResponse::errors(null, $e->getMessage(), 500);
        }
    }

    /**
     * Update passenger details on an active booking.
     * PUT /api/v1/flight/bookings/{identifier}/passengers/{passengerId}
     */
    public function updatePassenger(Request $request, string $identifier, int $passengerId): JsonResponse
    {
        $validated = $request->validate([
            'title'                 => 'nullable|string|max:20',
            'first_name'            => 'nullable|string|max:100',
            'last_name'             => 'nullable|string|max:100',
            'date_of_birth'         => 'nullable|date',
            'gender'                => 'nullable|string',
            'nationality'           => 'nullable|string|max:10',
            'passport_number'       => 'nullable|string|max:50',
            'passport_expiry'       => 'nullable|date',
            'passport_country'      => 'nullable|string|max:10',
            'is_international'      => 'nullable|boolean',
            'seat_preference'       => 'nullable|string|max:50',
            'meal_preference'       => 'nullable|string|max:50',
            'frequent_flyer_number' => 'nullable|string|max:50',
            'contact_email'         => 'nullable|email|max:150',
            'contact_phone'         => 'nullable|string|max:50',
        ]);

        try {
            $tenant = $this->resolveTenant($request);
            $passenger = $this->bookingService->updatePassenger($identifier, $passengerId, $validated, $tenant);

            return ApiResponse::success([
                'id'               => $passenger->id,
                'booking_id'       => $passenger->booking_id,
                'passenger_type'   => $passenger->passenger_type->value,
                'full_name'        => $passenger->full_name,
                'first_name'       => $passenger->first_name,
                'last_name'        => $passenger->last_name,
                'passport_number'  => $passenger->passport_number,
                'seat_preference'  => $passenger->seat_preference,
                'meal_preference'  => $passenger->meal_preference,
                'message'          => 'Passenger details updated successfully.',
            ]);
        } catch (DomainException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return ApiResponse::errors(null, $e->getMessage(), 500);
        }
    }
}
