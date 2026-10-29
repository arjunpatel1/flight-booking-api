<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Models\FlightAirport;
use Modules\Flight\Services\Catalog\FlightCatalogTenant;
use Modules\Support\ApiResponse;

class FlightAirportController extends Controller
{
    /**
     * Search and list airports.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FlightAirport::ownedByCatalog();

        if ($request->boolean('active_only', false)) {
            $query->where('is_active', true);
        }

        if ($search = $request->query('query', $request->query('search'))) {
            $query->search($search);
        }

        if ($request->boolean('paginate', false)) {
            return ApiResponse::pagination($query->paginate($request->integer('per_page', 20)));
        }

        $airports = $query->limit($request->integer('limit', 50))->get();

        return ApiResponse::success($airports);
    }

    /**
     * Store a new airport.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'iata_code' => ['required', 'string', 'size:3', FlightCatalogTenant::unique('flight_airports', 'iata_code')],
            'icao_code' => ['nullable', 'string', 'size:4'],
            'name' => ['required', 'string', 'max:191'],
            'city' => ['required', 'string', 'max:100'],
            'city_code' => ['required', 'string', 'size:3'],
            'country' => ['required', 'string', 'max:100'],
            'country_code' => ['required', 'string', 'size:2'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'timezone' => ['nullable', 'string', 'max:50'],
            'terminals' => ['nullable'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['iata_code'] = strtoupper($validated['iata_code']);
        $validated['city_code'] = strtoupper($validated['city_code']);
        $validated['country_code'] = strtoupper($validated['country_code']);

        if (isset($validated['terminals'])) {
            $validated['terminals'] = $this->sanitizeTerminals($validated['terminals']);
        }

        $airport = FlightAirport::create($validated);

        return ApiResponse::created($airport, message: 'Airport created successfully');
    }

    /**
     * Show airport details by IATA code or ID.
     */
    public function show(string $identifier): JsonResponse
    {
        $airport = is_numeric($identifier)
            ? FlightAirport::ownedByCatalog()->findOrFail($identifier)
            : FlightAirport::ownedByCatalog()->where('iata_code', strtoupper($identifier))->firstOrFail();

        return ApiResponse::success($airport);
    }

    /**
     * Update an existing airport.
     */
    public function update(Request $request, string $identifier): JsonResponse
    {
        $airport = is_numeric($identifier)
            ? FlightAirport::ownedByCatalog()->findOrFail($identifier)
            : FlightAirport::ownedByCatalog()->where('iata_code', strtoupper($identifier))->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'icao_code' => ['nullable', 'string', 'size:4'],
            'city' => ['sometimes', 'required', 'string', 'max:100'],
            'city_code' => ['sometimes', 'required', 'string', 'size:3'],
            'country' => ['sometimes', 'required', 'string', 'max:100'],
            'country_code' => ['sometimes', 'required', 'string', 'size:2'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'timezone' => ['nullable', 'string', 'max:50'],
            'terminals' => ['nullable'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (isset($validated['city_code'])) {
            $validated['city_code'] = strtoupper($validated['city_code']);
        }
        if (isset($validated['country_code'])) {
            $validated['country_code'] = strtoupper($validated['country_code']);
        }
        if (array_key_exists('terminals', $validated)) {
            $validated['terminals'] = $this->sanitizeTerminals($validated['terminals']);
        }

        $airport->update($validated);

        return ApiResponse::success($airport->fresh(), 'Airport updated successfully');
    }

    /**
     * Delete an airport.
     */
    public function destroy(string $identifier): JsonResponse
    {
        $airport = is_numeric($identifier)
            ? FlightAirport::ownedByCatalog()->findOrFail($identifier)
            : FlightAirport::ownedByCatalog()->where('iata_code', strtoupper($identifier))->firstOrFail();

        $airport->delete();

        return ApiResponse::success(null, 'Airport deleted successfully');
    }

    /**
     * Sanitize terminals input into an array of unique trimmed strings.
     */
    protected function sanitizeTerminals(mixed $terminals): ?array
    {
        if (empty($terminals)) {
            return [];
        }

        if (is_string($terminals)) {
            $terminals = array_map('trim', explode(',', $terminals));
        }

        if (! is_array($terminals)) {
            return [];
        }

        $cleaned = [];
        foreach ($terminals as $t) {
            if (is_string($t) || is_numeric($t)) {
                $val = trim((string) $t);
                if ($val !== '' && ! in_array($val, $cleaned, true)) {
                    $cleaned[] = $val;
                }
            }
        }

        return $cleaned;
    }
}
