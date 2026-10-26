<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Services\Booking\FlightBookingService;
use Modules\Flight\Models\FlightRoute;
use Modules\Support\ApiResponse;

class FlightRouteController extends Controller
{
    public function __construct(protected FlightBookingService $bookingService)
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
            'origin_airport_id' => ['required', 'exists:flight_airports,id'],
            'destination_airport_id' => [
                'required',
                'exists:flight_airports,id',
                'different:origin_airport_id',
            ],
            'distance_km' => ['nullable', 'integer', 'min:0'],
            'flight_duration_minutes' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ] + $this->flightDetailsRules());

        $validated['tenant_id'] = $validated['tenant_id'] ?? $tenantId;
        $route = FlightRoute::create($validated);
        $route->load(['originAirport', 'destinationAirport']);

        return ApiResponse::created($route, message: 'Flight route created successfully');
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
            'origin_airport_id' => ['sometimes', 'exists:flight_airports,id'],
            'destination_airport_id' => ['sometimes', 'exists:flight_airports,id', 'different:origin_airport_id'],
            'distance_km' => ['nullable', 'integer', 'min:0'],
            'flight_duration_minutes' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ] + $this->flightDetailsRules());

        $updatedBookings = DB::transaction(function () use ($route, $validated): int {
            $route->update($validated);

            if (! array_key_exists('pnr_number', $validated) || blank($validated['pnr_number'])) {
                return 0;
            }

            return $this->bookingService->assignRoutePnr($route->fresh());
        });

        $route->load(['originAirport', 'destinationAirport']);

        return ApiResponse::success($route->fresh(), $updatedBookings > 0
            ? "Flight route updated. PNR assigned to {$updatedBookings} paid booking(s)."
            : 'Flight route updated successfully');
    }

    /**
     * Delete route.
     */
    public function destroy(int $id): JsonResponse
    {
        $route = FlightRoute::findOrFail($id);

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
