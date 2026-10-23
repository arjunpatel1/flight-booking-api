<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Models\FlightSchedule;
use Modules\Support\ApiResponse;

class FlightScheduleController extends Controller
{
    /**
     * List tenant flight schedules.
     */
    public function index(Request $request): JsonResponse
    {
        $schedules = FlightSchedule::query()
            ->with(['route.originAirport', 'route.destinationAirport', 'airline'])
            ->filters($request->all())
            ->sortBy($request->get('sort_by', 'created_at'), $request->get('sort_direction', 'desc'))
            ->paginate($request->get('per_page', 20));

        return ApiResponse::pagination($schedules);
    }

    /**
     * Store a new flight schedule.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id
            ?? ($request->header('X-Tenant-ID') ? (int) $request->header('X-Tenant-ID') : null)
            ?? ($request->filled('tenant_id') ? (int) $request->input('tenant_id') : null)
            ?? 1;

        $validated = $request->validate([
            'tenant_id' => ['nullable', 'integer'],
            'route_id' => ['required', 'exists:flight_routes,id'],
            'airline_id' => ['required', 'exists:flight_airlines,id'],
            'flight_number' => ['required', 'string', 'max:10'],
            'departure_time' => ['required', 'string'],
            'arrival_time' => ['required', 'string'],
            'day_offset' => ['nullable', 'integer', 'min:0', 'max:2'],
            'days_of_week' => ['required', 'array', 'min:1'],
            'days_of_week.*' => ['integer', 'between:1,7'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['tenant_id'] = $validated['tenant_id'] ?? $tenantId;
        $validated['flight_number'] = strtoupper($validated['flight_number']);

        $schedule = FlightSchedule::create($validated);
        $schedule->load(['route.originAirport', 'route.destinationAirport', 'airline']);

        return ApiResponse::created($schedule, message: 'Flight schedule created successfully');
    }

    /**
     * Show schedule details.
     */
    public function show(int $id): JsonResponse
    {
        $schedule = FlightSchedule::with(['route.originAirport', 'route.destinationAirport', 'airline', 'instances'])->findOrFail($id);

        return ApiResponse::success($schedule);
    }

    /**
     * Update flight schedule.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $schedule = FlightSchedule::findOrFail($id);

        $validated = $request->validate([
            'flight_number' => ['sometimes', 'required', 'string', 'max:10'],
            'departure_time' => ['sometimes', 'required', 'string'],
            'arrival_time' => ['sometimes', 'required', 'string'],
            'day_offset' => ['nullable', 'integer', 'min:0', 'max:2'],
            'days_of_week' => ['sometimes', 'required', 'array', 'min:1'],
            'days_of_week.*' => ['integer', 'between:1,7'],
            'valid_from' => ['sometimes', 'required', 'date'],
            'valid_until' => ['sometimes', 'required', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (isset($validated['flight_number'])) {
            $validated['flight_number'] = strtoupper($validated['flight_number']);
        }

        $schedule->update($validated);
        $schedule->load(['route.originAirport', 'route.destinationAirport', 'airline']);

        return ApiResponse::success($schedule->fresh(), 'Flight schedule updated successfully');
    }

    /**
     * Delete schedule.
     */
    public function destroy(int $id): JsonResponse
    {
        $schedule = FlightSchedule::findOrFail($id);
        $schedule->delete();

        return ApiResponse::success(null, 'Flight schedule deleted successfully');
    }
}
