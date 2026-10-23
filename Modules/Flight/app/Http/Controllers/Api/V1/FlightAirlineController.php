<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Models\FlightAirline;
use Modules\Support\ApiResponse;

class FlightAirlineController extends Controller
{
    /**
     * Search and list airlines.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FlightAirline::query();

        if ($request->boolean('active_only', false)) {
            $query->where('is_active', true);
        }

        if ($search = $request->query('query', $request->query('search'))) {
            $query->search($search);
        }

        if ($request->boolean('paginate', false)) {
            return ApiResponse::pagination($query->paginate($request->integer('per_page', 20)));
        }

        $airlines = $query->limit($request->integer('limit', 50))->get();

        return ApiResponse::success($airlines);
    }

    /**
     * Store a new airline.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'iata_code' => ['required', 'string', 'size:2', 'unique:flight_airlines,iata_code'],
            'icao_code' => ['nullable', 'string', 'size:3'],
            'name' => ['required', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'country_code' => ['nullable', 'string', 'max:3'],
            'logo_url' => ['nullable', 'string', 'max:255'],
            'alliance' => ['nullable', 'string', 'in:star_alliance,skyteam,oneworld,none'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['iata_code'] = strtoupper($validated['iata_code']);
        if (isset($validated['icao_code'])) {
            $validated['icao_code'] = strtoupper($validated['icao_code']);
        }
        if (isset($validated['country_code'])) {
            $validated['country_code'] = strtoupper($validated['country_code']);
        }

        $airline = FlightAirline::create($validated);

        return ApiResponse::created($airline, message: 'Airline created successfully');
    }

    /**
     * Show airline details.
     */
    public function show(string $identifier): JsonResponse
    {
        $airline = is_numeric($identifier)
            ? FlightAirline::findOrFail($identifier)
            : FlightAirline::where('iata_code', strtoupper($identifier))->firstOrFail();

        return ApiResponse::success($airline);
    }

    /**
     * Update an airline.
     */
    public function update(Request $request, string $identifier): JsonResponse
    {
        $airline = is_numeric($identifier)
            ? FlightAirline::findOrFail($identifier)
            : FlightAirline::where('iata_code', strtoupper($identifier))->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'icao_code' => ['nullable', 'string', 'size:3'],
            'country' => ['nullable', 'string', 'max:100'],
            'country_code' => ['nullable', 'string', 'max:3'],
            'logo_url' => ['nullable', 'string', 'max:255'],
            'alliance' => ['nullable', 'string', 'in:star_alliance,skyteam,oneworld,none'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (isset($validated['icao_code'])) {
            $validated['icao_code'] = strtoupper($validated['icao_code']);
        }
        if (isset($validated['country_code'])) {
            $validated['country_code'] = strtoupper($validated['country_code']);
        }

        $airline->update($validated);

        return ApiResponse::success($airline->fresh(), 'Airline updated successfully');
    }

    /**
     * Delete an airline.
     */
    public function destroy(string $identifier): JsonResponse
    {
        $airline = is_numeric($identifier)
            ? FlightAirline::findOrFail($identifier)
            : FlightAirline::where('iata_code', strtoupper($identifier))->firstOrFail();

        $airline->delete();

        return ApiResponse::success(null, 'Airline deleted successfully');
    }
}
