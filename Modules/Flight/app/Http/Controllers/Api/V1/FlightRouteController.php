<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Services\Booking\FlightPnrAllocationService;
use Modules\Flight\Services\Catalog\FlightCatalogTenant;
use Modules\Flight\Models\FlightRoute;
use Modules\Saas\Models\Tenant;
use Modules\Support\ApiResponse;

class FlightRouteController extends Controller
{
    public function __construct(protected FlightPnrAllocationService $pnrAllocation)
    {
    }

    /**
     * List tenant flight routes.
     */
    public function index(Request $request): JsonResponse
    {
        $routes = FlightRoute::query()
            ->with(['originAirport', 'destinationAirport'])
            ->filters($request->all())
            ->sortBy([['key' => $request->get('sort_by', 'created_at'), 'order' => $request->get('sort_direction', 'desc')]])
            ->paginate($request->get('per_page', 20));

        // A route without a PNR: how many paid bookings are waiting for one, so
        // the page can offer "Allocate PNR (n)". Routes with a PNR hand it to
        // their bookings automatically, so nothing waits on them.
        $routes->getCollection()->each(fn (FlightRoute $route) => $route->setAttribute(
            'pnr_pending_count',
            blank($route->pnr_number) ? $this->pnrAllocation->pendingBookingsForRoute($route)->count() : 0,
        ));

        return ApiResponse::pagination($routes);
    }

    /**
     * Store a new route.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id
            ?? ($request->header('X-Tenant-ID') ? (int) $request->header('X-Tenant-ID') : null)
            ?? ($request->filled('tenant_id') ? (int) $request->input('tenant_id') : null)
            ?? 1;

        $validated = $request->validate([
            'tenant_id' => ['nullable', 'integer'],
            'origin_airport_id' => ['required', FlightCatalogTenant::exists('flight_airports')],
            'destination_airport_id' => [
                'required',
                FlightCatalogTenant::exists('flight_airports'),
                'different:origin_airport_id',
            ],
            'distance_km' => ['nullable', 'integer', 'min:0'],
            'flight_duration_minutes' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ] + $this->flightDetailsRules());

        $validated['tenant_id'] = $validated['tenant_id'] ?? $tenantId;
        $this->ensureNotDuplicate($validated, (int) $validated['tenant_id']);
        $route = FlightRoute::create($this->withSeatCounts($validated, null));
        $route->load(['originAirport', 'destinationAirport']);

        return ApiResponse::created($route, message: 'Flight route created successfully');
    }

    /**
     * Public: the dates an active route flies between two airports, for the
     * customer calendar. Dates inside the tenant's minimum advance booking
     * window are left out, matching what search will actually return.
     */
    public function flyingDates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'origin' => ['required', 'string', 'size:3'],
            'destination' => ['required', 'string', 'size:3'],
        ]);

        $tenantId = $request->user()?->tenant_id
            ?? $request->attributes->get('tenant_id')
            ?? $request->attributes->get('tenant')?->id
            ?? $request->header('X-Tenant-ID')
            ?? $request->query('tenant_id');
        abort_unless($tenantId, 400, 'A tenant identifier is required to load flight dates.');

        $tenant = Tenant::query()->withoutGlobalScopes()->findOrFail((int) $tenantId);
        $minDays = max(0, (int) data_get($tenant->settings, 'flight_booking.minimum_advance_booking_days', 0));
        $airport = fn (string $code) => fn ($query) => $query->withoutGlobalScopes()->where('iata_code', strtoupper($code));

        $dates = FlightRoute::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->whereHas('originAirport', $airport($data['origin']))
            ->whereHas('destinationAirport', $airport($data['destination']))
            ->whereDate('flight_date', '>=', now()->startOfDay()->addDays($minDays))
            ->orderBy('flight_date')
            ->pluck('flight_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique()
            ->values();

        return ApiResponse::success(['dates' => $dates]);
    }

    /**
     * Show route details.
     */
    public function show(int $id): JsonResponse
    {
        $route = FlightRoute::with(['originAirport', 'destinationAirport', 'schedules'])->findOrFail($id);

        return ApiResponse::success($route);
    }

    /**
     * Update route.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $route = FlightRoute::findOrFail($id);

        $validated = $request->validate([
            'origin_airport_id' => ['sometimes', FlightCatalogTenant::exists('flight_airports')],
            'destination_airport_id' => ['sometimes', FlightCatalogTenant::exists('flight_airports'), 'different:origin_airport_id'],
            'distance_km' => ['nullable', 'integer', 'min:0'],
            'flight_duration_minutes' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ] + $this->flightDetailsRules());

        $this->ensureNotDuplicate(array_merge($route->only([
            'flight_number', 'origin_airport_id', 'destination_airport_id', 'flight_date', 'flight_time',
        ]), $validated), (int) $route->tenant_id, $route->id);
        // Locked so a booking made while this saves can't be overwritten.
        $route = DB::transaction(function () use ($route, $validated) {
            $locked = FlightRoute::query()->lockForUpdate()->findOrFail($route->id);
            $locked->update($this->withSeatCounts($validated, $locked));

            return $locked;
        });
        $route->load(['originAirport', 'destinationAirport']);

        // Saving a PNR no longer hands it to everyone waiting: the airline may
        // confirm fewer seats than we sold, so the admin picks who it covers
        // (see pnrPendingBookings / allocatePnr).
        $waiting = filled($route->pnr_number) ? $this->pnrAllocation->pendingBookingsForRoute($route)->count() : 0;

        return ApiResponse::success($route->fresh(['originAirport', 'destinationAirport']), $waiting > 0
            ? "Flight route updated. {$waiting} paid booking(s) are waiting for this PNR — choose who it covers."
            : 'Flight route updated successfully');
    }

    /** Paid bookings on this route's flight still waiting for a PNR, first paid first. */
    public function pnrPendingBookings(int $id): JsonResponse
    {
        $route = FlightRoute::findOrFail($id);

        return ApiResponse::success([
            'route_id' => $route->id,
            'pnr_number' => $route->pnr_number,
            // Pre-fills the dialog: the route's PNR, else the one last given to
            // bookings on this flight that day (same airline PNR, new batch).
            'suggested_pnr' => $route->pnr_number ?: $this->pnrAllocation->lastAllocatedPnr($route),
            'bookings' => $this->pnrAllocation->pendingBookingsForRoute($route)->map(fn ($booking) => [
                'booking_reference' => $booking->booking_reference,
                'contact_name' => $booking->contact_name,
                'contact_email' => $booking->contact_email,
                'contact_phone' => $booking->contact_phone,
                'passenger_count' => max(1, $booking->passengers->count()
                    ?: (int) $booking->pax_count_adult + (int) $booking->pax_count_child + (int) $booking->pax_count_infant),
                'passenger_names' => $booking->passengers->map(fn ($p) => trim("{$p->first_name} {$p->last_name}"))->filter()->values(),
                'total_amount' => (float) $booking->total_amount,
                'currency' => $booking->currency ?: 'INR',
                'paid_at' => $booking->confirmed_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * Give the route's (or a given) PNR to the chosen bookings; optionally sell
     * out everyone else still waiting (full refund + "sold out" email).
     */
    public function allocatePnr(Request $request, int $id): JsonResponse
    {
        $route = FlightRoute::findOrFail($id);
        $validated = $request->validate([
            'pnr' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9-]+$/'],
            'booking_references' => ['present', 'array', 'max:500'],
            'booking_references.*' => ['string', 'max:64'],
            'sold_out_remaining' => ['required', 'boolean'],
        ]);

        $pnr = $validated['pnr'] ?? $route->pnr_number;
        if (blank($pnr)) {
            return ApiResponse::errors(null, 'Enter the airline PNR first.', 422);
        }
        if ($validated['booking_references'] === [] && ! $validated['sold_out_remaining']) {
            return ApiResponse::errors(null, 'Choose at least one booking for this PNR.', 422);
        }

        try {
            // The PNR goes to the chosen bookings only, never onto the route:
            // the airline confirms seats batch by batch, so later bookings on
            // this flight keep waiting until the admin gives them a PNR too.
            $result = $this->pnrAllocation->allocate($route, $pnr, $validated['booking_references'], $validated['sold_out_remaining']);
        } catch (DomainException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        }

        $message = sprintf('PNR %s given to %d booking(s).', strtoupper($pnr), count($result['assigned']));
        if ($result['sold_out'] !== []) {
            $message .= sprintf(' %d booking(s) marked sold out with a full refund.', count($result['sold_out']));
        }
        if ($result['ticket_failed'] !== []) {
            $message .= ' E-tickets for ' . implode(', ', $result['ticket_failed']) . ' need a retry.';
        }

        return ApiResponse::success($result, $message);
    }

    /**
     * Delete route.
     */
    public function destroy(int $id): JsonResponse
    {
        $route = FlightRoute::findOrFail($id);

        try {
            app(\Modules\Flight\Services\Inventory\FlightDeletionGuard::class)->assertRouteFree($route);
        } catch (\DomainException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        try {
            DB::transaction(function () use ($route) {
                // Delete associated flight schedules if they have no active flight instances
                $route->schedules()->delete();
                $route->delete();
            });

            return ApiResponse::success(null, 'Flight route deleted successfully');
        } catch (\Illuminate\Database\QueryException $e) {
            return ApiResponse::error(
                'Cannot delete this flight route because active flight bookings or generated flight instances depend on it.',
                422
            );
        } catch (\Throwable $e) {
            return ApiResponse::error(
                'Failed to delete flight route: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Flight data entered on the route form. Nullable rules keep existing
     * clients that only send airport pairs compatible with this endpoint.
     */
    /**
     * One route per flight number, airport pair and departure date + time.
     */
    private function ensureNotDuplicate(array $attributes, int $tenantId, ?int $ignoreId = null): void
    {
        $flightNumber = strtoupper(trim((string) ($attributes['flight_number'] ?? '')));
        $flightDate = $attributes['flight_date'] ?? null;
        $flightTime = $attributes['flight_time'] ?? null;
        if ($flightNumber === '' || ! $flightDate || ! $flightTime) {
            return;
        }

        // The model may hand back a Carbon date; the column is TIME, so compare as
        // HH:MM:SS whether "02:10" or "02:10:00" was sent.
        $flightDate = Carbon::parse($flightDate)->toDateString();
        $flightTime = strlen((string) $flightTime) === 5 ? "{$flightTime}:00" : (string) $flightTime;

        $exists = FlightRoute::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('UPPER(TRIM(flight_number)) = ?', [$flightNumber])
            ->where('origin_airport_id', $attributes['origin_airport_id'] ?? null)
            ->where('destination_airport_id', $attributes['destination_airport_id'] ?? null)
            ->whereDate('flight_date', $flightDate)
            ->where('flight_time', $flightTime)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'flight_number' => "A route for {$flightNumber} on {$flightDate} at ".substr($flightTime, 0, 5).' between these airports already exists.',
            ]);
        }
    }

    /**
     * A dated route is the flight's seat pool on that date, shared by every
     * cabin: bookings and holds move consumed/available (FlightSeatPool). The
     * admin only sets the capacity; sold seats are never taken from the form.
     */
    private function withSeatCounts(array $validated, ?FlightRoute $route): array
    {
        unset($validated['available_seats'], $validated['consumed_seats']);

        $consumed = max(0, (int) ($route?->consumed_seats ?? 0));
        $total = array_key_exists('total_seats', $validated) && $validated['total_seats'] !== null
            ? (int) $validated['total_seats']
            : (int) ($route?->total_seats ?? $validated['total_adult_seats'] ?? 0);

        if ($total < $consumed) {
            throw ValidationException::withMessages([
                'total_seats' => "{$consumed} seat(s) are already sold on this flight, so total seats can't be lower than that.",
            ]);
        }

        return $validated + [
            'consumed_seats'  => $consumed,
            'available_seats' => $total - $consumed,
        ];
    }

    private function flightDetailsRules(): array
    {
        return [
            'flight_number' => ['nullable', 'string', 'max:20'],
            'flight_date' => ['nullable', 'date'],
            'flight_time' => ['nullable', 'regex:/^\\d{2}:\\d{2}(:\\d{2})?$/'],
            'end_date' => ['nullable', 'date'],
            'end_time' => ['nullable', 'regex:/^\\d{2}:\\d{2}(:\\d{2})?$/'],
            'purchase_date' => ['nullable', 'date'],
            'pnr_number' => ['nullable', 'string', 'max:50'],
            'departure_terminal' => ['nullable', 'string', 'max:20'],
            'arrival_terminal' => ['nullable', 'string', 'max:20'],
            'adult_count' => ['nullable', 'integer', 'min:0', 'max:999'],
            'child_count' => ['nullable', 'integer', 'min:0', 'max:999'],
            'total_seats' => ['nullable', 'integer', 'min:0'],
            'available_seats' => ['nullable', 'integer', 'min:0'],
            'consumed_seats' => ['nullable', 'integer', 'min:0'],
            'total_adult_seats' => ['nullable', 'integer', 'min:0'],
            'total_child_seats' => ['nullable', 'integer', 'min:0'],
            'available_adult_seats' => ['nullable', 'integer', 'min:0'],
            'available_child_seats' => ['nullable', 'integer', 'min:0'],
            'consumed_adult_seats' => ['nullable', 'integer', 'min:0'],
            'consumed_child_seats' => ['nullable', 'integer', 'min:0'],
            'total_seats_price' => ['nullable', 'numeric', 'min:0'],
            'adult_price' => ['nullable', 'numeric', 'min:0'],
            'child_price' => ['nullable', 'numeric', 'min:0'],
            'infant_price' => ['nullable', 'numeric', 'min:0'],
            'gst_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'gst_amount' => ['nullable', 'numeric', 'min:0'],
            'total_adult_price_with_gst' => ['nullable', 'numeric', 'min:0'],
            'total_child_price_with_gst' => ['nullable', 'numeric', 'min:0'],
            'total_infant_price_with_gst' => ['nullable', 'numeric', 'min:0'],
            'fare_families' => ['nullable', 'array'],
            'fare_families.*' => ['string', 'max:50'],
            'fare_family_prices' => ['nullable', 'array'],
            'fare_family_pax_prices' => ['nullable', 'array'],
            'cabin_fares' => ['nullable', 'array'],
            'stops' => ['nullable', 'integer', 'min:0', 'max:10'],
            'stops_type' => ['nullable', 'string', 'max:100'],
            'stops_types' => ['nullable', 'array'],
            'stops_types.*' => ['string', 'max:50'],
        ];
    }
}
