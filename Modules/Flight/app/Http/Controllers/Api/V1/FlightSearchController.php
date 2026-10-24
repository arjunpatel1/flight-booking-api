<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Models\FlightAirport;
use Modules\Flight\Models\FlightAirline;
use Modules\Flight\Services\Search\DTOs\FlightSearchParams;
use Modules\Flight\Services\Search\FlightSearchEngine;
use Modules\Saas\Models\Tenant;
use Modules\Support\ApiResponse;

class FlightSearchController extends Controller
{
    public function __construct(
        protected FlightSearchEngine $searchEngine
    ) {}

    protected function resolveTenant(Request $request, ?int $requestedTenantId = null): ?Tenant
    {
        $tenantId = $request->user()?->tenant_id
            ?? auth()->user()?->tenant_id
            ?? $request->attributes->get('tenant_id')
            ?? $request->attributes->get('tenant')?->id
            ?? $requestedTenantId;
        if ($tenantId) {
            return Tenant::find($tenantId);
        }
        return null;
    }

    /**
     * Search flights across internal inventory and external suppliers.
     * POST /api/v1/flight/search or GET /api/v1/flight/search
     */
    public function search(Request $request): JsonResponse
    {
        if ($request->input('trip_type') === 'multi_city' && $request->has('legs')) {
            return $this->searchMultiCity($request);
        }

        $validated = $request->validate([
            'origin'                => 'required|string|size:3',
            'destination'           => 'required|string|size:3|different:origin',
            'departure_date'        => 'required|date_format:Y-m-d',
            'return_date'           => 'nullable|date_format:Y-m-d|after_or_equal:departure_date',
            'trip_type'             => 'nullable|string|in:one_way,round_trip,multi_city',
            'adults'                => 'nullable|integer|min:1|max:9',
            'children'              => 'nullable|integer|min:0|max:9',
            'infants'               => 'nullable|integer|min:0|max:9',
            'cabin_class'           => ['nullable', 'string', Rule::exists('flight_cabin_classes', 'code')->where('is_active', true)],
            'airlines'              => 'nullable|array',
            'airlines.*'            => 'string|size:2',
            'max_stops'             => 'nullable|integer|min:0|max:3',
            'min_price'             => 'nullable|numeric|min:0',
            'max_price'             => 'nullable|numeric|min:0',
            'departure_time_window' => 'nullable|string|in:morning,afternoon,evening,night',
            'refundable_only'       => 'nullable|boolean',
            'baggage_included'      => 'nullable|boolean',
            'supplier'              => 'nullable|string',
            'sort_by'               => 'nullable|string|in:recommended,price_asc,price_desc,duration_asc,departure_asc,departure_desc',
            'currency'              => 'nullable|string|size:3',
            'tenant_id'             => 'nullable|integer|exists:tenants,id',
        ]);

        try {
            $params = FlightSearchParams::fromArray($validated);
            $tenant = $this->resolveTenant($request, isset($validated['tenant_id']) ? (int) $validated['tenant_id'] : null);
            try { app(\Modules\Flight\Services\Hold\FlightHoldService::class)->expireActiveHolds(); } catch (\Throwable $e) {}
            $response = $this->searchEngine->search($params, $tenant);

            return ApiResponse::success($response->toArray(), 'Flight search executed successfully.');
        } catch (Exception $e) {
            return ApiResponse::errors(null, 'Flight search failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Search multi-city flight itineraries across multiple legs.
     * POST /api/v1/flight/search/multi-city
     */
    public function searchMultiCity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'legs'                  => 'required|array|min:2|max:6',
            'legs.*.origin'         => 'required|string|size:3',
            'legs.*.destination'    => 'required|string|size:3|different:legs.*.origin',
            'legs.*.departure_date' => 'required|date_format:Y-m-d',
            'adults'                => 'nullable|integer|min:1|max:9',
            'children'              => 'nullable|integer|min:0|max:9',
            'infants'               => 'nullable|integer|min:0|max:9',
            'cabin_class'           => ['nullable', 'string', Rule::exists('flight_cabin_classes', 'code')->where('is_active', true)],
            'currency'              => 'nullable|string|size:3',
            'sort_by'               => 'nullable|string|in:recommended,price_asc,price_desc,duration_asc,departure_asc,departure_desc',
            'tenant_id'             => 'nullable|integer|exists:tenants,id',
        ]);

        try {
            $tenant = $this->resolveTenant($request, isset($validated['tenant_id']) ? (int) $validated['tenant_id'] : null);
            $results = [];

            foreach ($validated['legs'] as $index => $leg) {
                $params = FlightSearchParams::fromArray([
                    'origin'         => $leg['origin'],
                    'destination'    => $leg['destination'],
                    'departure_date' => $leg['departure_date'],
                    'trip_type'      => 'one_way',
                    'adults'         => $validated['adults'] ?? 1,
                    'children'       => $validated['children'] ?? 0,
                    'infants'        => $validated['infants'] ?? 0,
                    'cabin_class'    => $validated['cabin_class'] ?? 'ECONOMY',
                    'currency'       => $validated['currency'] ?? 'INR',
                    'sort_by'        => $validated['sort_by'] ?? 'recommended',
                ]);

                try { app(\Modules\Flight\Services\Hold\FlightHoldService::class)->expireActiveHolds(); } catch (\Throwable $e) {}
            $response = $this->searchEngine->search($params, $tenant);
                $results[] = [
                    'leg_index'      => $index,
                    'origin'         => strtoupper($leg['origin']),
                    'destination'    => strtoupper($leg['destination']),
                    'departure_date' => $leg['departure_date'],
                    'search_id'      => $response->searchId,
                    'itineraries'    => $response->itineraries,
                    'best_fares'     => $response->bestFares,
                    'filters'        => $response->filtersAvailable,
                ];
            }

            return ApiResponse::success([
                'trip_type'  => 'multi_city',
                'legs'       => $results,
                'total_legs' => count($results),
            ], 'Multi-city flight search executed successfully.');
        } catch (Exception $e) {
            return ApiResponse::errors(null, 'Multi-city search failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get search facet filters and airport/airline catalogs.
     * GET /api/v1/flight/search/filters
     */
    public function filters(Request $request): JsonResponse
    {
        $airports = FlightAirport::active()->take(20)->get(['iata_code', 'name', 'city', 'country']);
        $airlines = FlightAirline::active()->get(['iata_code', 'name']);

        return ApiResponse::success([
            'popular_airports' => $airports,
            'airlines'         => $airlines,
            'cabin_classes'    => [
                ['code' => 'ECONOMY', 'name' => 'Economy'],
                ['code' => 'PREMIUM_ECONOMY', 'name' => 'Premium Economy'],
                ['code' => 'BUSINESS', 'name' => 'Business Class'],
                ['code' => 'FIRST', 'name' => 'First Class'],
            ],
            'time_windows'     => [
                ['id' => 'morning',   'label' => 'Morning (05:00 - 11:59)'],
                ['id' => 'afternoon', 'label' => 'Afternoon (12:00 - 17:59)'],
                ['id' => 'evening',   'label' => 'Evening (18:00 - 23:59)'],
                ['id' => 'night',     'label' => 'Night (00:00 - 04:59)'],
            ],
        ]);
    }

    /**
     * Frontend Search Results and Live Booking Engine Workspace.
     * GET /api/v1/flight/search/workspace
     */
    public function workspace(Request $request)
    {
        $airports = FlightAirport::active()->get(['iata_code', 'name', 'city']);
        $airlines = FlightAirline::active()->get(['iata_code', 'name']);

        return view('flight::search.workspace', [
            'airports'       => $airports,
            'airlines'       => $airlines,
            'defaultOrigin'  => 'DEL',
            'defaultDest'    => 'BOM',
            'defaultDepDate' => now()->addDays(7)->format('Y-m-d'),
        ]);
    }
}
