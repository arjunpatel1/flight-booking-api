<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Models\FlightAirline;
use Modules\Flight\Models\FlightAirport;
use Modules\Flight\Models\FlightCabinClass;
use Modules\Support\ApiResponse;

class FlightMetaController extends Controller
{
    /**
     * Get flight platform metadata and capability dictionary.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'module' => 'flight',
            'version' => '1.0.0',
            'status' => 'active',
            'capabilities' => [
                'b2c_booking' => true,
                'b2b_network' => true,
                'wallet_ledger' => true,
                'multi_city' => true,
                'ancillaries' => true,
                'seat_maps' => true,
            ],
            'cabin_classes' => FlightCabinClass::ownedByCatalog()->where('is_active', true)
                ->orderBy('sort_order')->get(['code as value', 'name as label']),
            'trip_types' => [
                ['value' => 'one_way', 'label' => 'One Way'],
                ['value' => 'round_trip', 'label' => 'Round Trip'],
                ['value' => 'multi_city', 'label' => 'Multi City'],
            ],
            'passenger_types' => [
                ['value' => 'adult', 'label' => 'Adult (12+ yrs)'],
                ['value' => 'child', 'label' => 'Child (2-11 yrs)'],
                ['value' => 'infant', 'label' => 'Infant (Under 2 yrs)'],
            ],
            'stats' => [
                'total_airports' => FlightAirport::ownedByCatalog()->count(),
                'total_airlines' => FlightAirline::ownedByCatalog()->count(),
            ],
        ]);
    }
}
