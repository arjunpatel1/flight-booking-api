<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Models\FlightAircraft;
use Modules\Flight\Services\Catalog\FlightCatalogTenant;
use Modules\Support\ApiResponse;

class FlightAircraftController extends Controller
{
    /**
     * Search and list aircraft equipment.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FlightAircraft::ownedByCatalog();

        if ($request->boolean('active_only', false)) {
            $query->where('is_active', true);
        }

        if ($search = $request->query('query', $request->query('search'))) {
            $query->search($search);
        }

        if ($request->boolean('paginate', false)) {
            return ApiResponse::pagination($query->paginate($request->integer('per_page', 20)));
        }

        $aircraft = $query->limit($request->integer('limit', 50))->get();

        return ApiResponse::success($aircraft);
    }

    /**
     * Store a new aircraft.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'iata_code' => ['required', 'string', 'size:3', FlightCatalogTenant::unique('flight_aircraft', 'iata_code')],
            'aircraft_type' => ['nullable', 'string', 'max:50'],
            'model' => ['required', 'string', 'max:100'],
            'registration' => ['nullable', 'string', 'max:30'],
            'manufacturer' => ['required', 'string', 'max:100'],
            'total_seats' => ['required', 'integer', 'min:1'],
            'seat_configuration' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['iata_code'] = strtoupper($validated['iata_code']);

        $aircraft = FlightAircraft::create($validated);

        return ApiResponse::created($aircraft, message: 'Aircraft created successfully');
    }

    /**
     * Show aircraft details.
     */
    public function show(string $identifier): JsonResponse
    {
        $aircraft = is_numeric($identifier)
            ? FlightAircraft::ownedByCatalog()->findOrFail($identifier)
            : FlightAircraft::ownedByCatalog()->where('iata_code', strtoupper($identifier))->firstOrFail();

        return ApiResponse::success($aircraft);
    }

    /**
     * Update aircraft.
     */
    public function update(Request $request, string $identifier): JsonResponse
    {
        $aircraft = is_numeric($identifier)
            ? FlightAircraft::ownedByCatalog()->findOrFail($identifier)
            : FlightAircraft::ownedByCatalog()->where('iata_code', strtoupper($identifier))->firstOrFail();

        $validated = $request->validate([
            'aircraft_type' => ['nullable', 'string', 'max:50'],
            'model' => ['sometimes', 'required', 'string', 'max:100'],
            'registration' => ['nullable', 'string', 'max:30'],
            'manufacturer' => ['sometimes', 'required', 'string', 'max:100'],
            'total_seats' => ['sometimes', 'required', 'integer', 'min:1'],
            'seat_configuration' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $aircraft->update($validated);

        return ApiResponse::success($aircraft->fresh(), 'Aircraft updated successfully');
    }

    /**
     * Delete aircraft.
     */
    public function destroy(string $identifier): JsonResponse
    {
        $aircraft = is_numeric($identifier)
            ? FlightAircraft::ownedByCatalog()->findOrFail($identifier)
            : FlightAircraft::ownedByCatalog()->where('iata_code', strtoupper($identifier))->firstOrFail();

        $aircraft->delete();

        return ApiResponse::success(null, 'Aircraft deleted successfully');
    }
}
