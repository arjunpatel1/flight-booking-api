<?php

namespace Modules\Flight\Services\Search;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Flight\Models\FlightAirport;
use Modules\Flight\Models\FlightFare;
use Modules\Flight\Models\FlightInventory;
use Modules\Flight\Models\FlightSupplier;
use Modules\Flight\Services\Pricing\FlightFareAggregationEngine;
use Modules\Flight\Services\Pricing\FlightPricingEngine;
use Modules\Flight\Services\Search\DTOs\FlightSearchParams;
use Modules\Flight\Services\Search\DTOs\FlightSearchResponse;
use Modules\Flight\Services\Supplier\DTOs\SupplierSearchParams;
use Modules\Flight\Services\Supplier\SupplierManager;
use Modules\Saas\Models\Tenant;

class FlightSearchEngine
{
    public function __construct(
        protected FlightPricingEngine $pricingEngine,
        protected FlightFareAggregationEngine $aggregationEngine,
        protected SupplierManager $supplierManager,
    ) {}

    /**
     * Execute comprehensive flight search aggregating internal inventory and external suppliers.
     */
    public function search(FlightSearchParams $params, ?Tenant $tenant = null): FlightSearchResponse
    {
        $startTime = microtime(true);
        $searchId = 'SRCH-' . strtoupper(Str::random(10));

        // 1. Search Outbound Itineraries
        $outboundRaw = $this->searchOneWay($params, $tenant);

        // 2. Search Return Itineraries if Round Trip
        $returnRaw = [];
        if ($params->isRoundTrip()) {
            $returnParams = new FlightSearchParams(
                origin:              $params->destination,
                destination:         $params->origin,
                departureDate:       $params->returnDate,
                returnDate:          null,
                tripType:            'one_way',
                adults:              $params->adults,
                children:            $params->children,
                infants:             $params->infants,
                cabinClass:          $params->cabinClass,
                airlines:            $params->airlines,
                maxStops:            $params->maxStops,
                minPrice:            $params->minPrice,
                maxPrice:            $params->maxPrice,
                departureTimeWindow: $params->departureTimeWindow,
                refundableOnly:      $params->refundableOnly,
                baggageIncluded:     $params->baggageIncluded,
                supplier:            $params->supplier,
                sortBy:              $params->sortBy,
                currency:            $params->currency,
            );
            $returnRaw = $this->searchOneWay($returnParams, $tenant);
        }

        // 3. Global Best Fares Evaluation across all outbound options
        $bestFares = $this->evaluateBestFares($outboundRaw['all_fares']);

        // 4. Extract Filter Facets (calculated from raw outbound before applying client filters)
        $filtersAvailable = $this->extractFilterFacets($outboundRaw['raw_itineraries']);

        // 5. Apply Filters and Sorting to Outbound
        $filteredOutbound = $this->applyFiltersAndSorting($outboundRaw['itineraries'], $params);

        // 6. Apply Filters and Sorting to Return (if round trip)
        $filteredReturn = [];
        if (! empty($returnRaw)) {
            $filteredReturn = $this->applyFiltersAndSorting($returnRaw['itineraries'], $params);
        }

        $executionTimeMs = round((microtime(true) - $startTime) * 1000, 2);

        return new FlightSearchResponse(
            success:           true,
            searchId:          $searchId,
            params:            $params->toArray(),
            itineraries:       $filteredOutbound,
            returnItineraries: $filteredReturn,
            bestFares:         $bestFares,
            filtersAvailable:  $filtersAvailable,
            meta: [
                'currency'          => $params->currency,
                'execution_time_ms' => $executionTimeMs,
                'timestamp'         => now()->toIso8601String(),
                'total_results'     => count($filteredOutbound),
                'minimum_advance_booking_days' => $tenant
                    ? (int) data_get($tenant->settings, 'flight_booking.minimum_advance_booking_days', 0)
                    : null,
            ],
        );
    }

    /**
     * Search and aggregate a single leg (outbound or return).
     */
    protected function searchOneWay(FlightSearchParams $params, ?Tenant $tenant = null): array
    {
        $rawItineraries = [];
        $allFaresList = [];

        // Apply the tenant booking window to supplier results as well as internal inventory.
        $minimumAdvanceDays = $tenant
            ? (int) data_get($tenant->settings, 'flight_booking.minimum_advance_booking_days', 0)
            : 0;
        if (Carbon::parse($params->departureDate)->startOfDay()->lt(Carbon::today()->addDays($minimumAdvanceDays))) {
            return ['itineraries' => [], 'raw_itineraries' => [], 'all_fares' => []];
        }

        // 1. Search Internal Inventory
        $internalItineraries = $this->searchInternalInventory($params, $tenant);
        foreach ($internalItineraries as $itinerary) {
            $key = $this->generateItineraryKey($itinerary);
            $rawItineraries[$key] = $itinerary;
        }

        // 2. Search Configured Suppliers
        $supplierItineraries = $this->searchSuppliers($params);
        foreach ($supplierItineraries as $itinerary) {
            $key = $this->generateItineraryKey($itinerary);
            if (isset($rawItineraries[$key])) {
                // Deduplicate Itinerary & Aggregate Multiple Fares under single flight
                $rawItineraries[$key] = $this->mergeItineraries($rawItineraries[$key], $itinerary);
            } else {
                $rawItineraries[$key] = $itinerary;
            }
        }

        // 3. Build flattened collection of fares for the Best Fare Engine
        foreach ($rawItineraries as &$itinerary) {
            // Sort fares by final price ascending
            usort($itinerary['fares'], fn ($a, $b) => $a['price']['final_customer_price'] <=> $b['price']['final_customer_price']);
            $itinerary['min_price'] = $itinerary['fares'][0]['price']['final_customer_price'] ?? 0.0;
            $itinerary['max_price'] = end($itinerary['fares'])['price']['final_customer_price'] ?? $itinerary['min_price'];

            foreach ($itinerary['fares'] as $fare) {
                $allFaresList[] = [
                    'fare' => $fare,
                    'itinerary' => $itinerary,
                ];
            }
        }
        unset($itinerary);

        return [
            'itineraries'     => array_values($rawItineraries),
            'raw_itineraries' => array_values($rawItineraries),
            'all_fares'       => $allFaresList,
        ];
    }

    /**
     * Search Internal Flight Inventory.
     */
    protected function searchInternalInventory(FlightSearchParams $params, ?Tenant $tenant = null): array
    {
        $originAirport = FlightAirport::where('iata_code', $params->origin)->first();
        $destAirport = FlightAirport::where('iata_code', $params->destination)->first();

        $query = FlightInventory::active()
            ->with(['airline', 'originAirport', 'destinationAirport', 'aircraft', 'fares.fareFamily']);

        if ($tenant) {
            $query->where('tenant_id', $tenant->id);
        }

        // Route matching
        if ($originAirport && $destAirport) {
            $query->where('origin_airport_id', $originAirport->id)
                  ->where('destination_airport_id', $destAirport->id);
        } else {
            $query->whereHas('originAirport', fn ($q) => $q->where('iata_code', $params->origin))
                  ->whereHas('destinationAirport', fn ($q) => $q->where('iata_code', $params->destination));
        }

        // Minimum capacity
        $totalPax = $params->totalPassengers();
        $query->where('available_seats', '>=', $totalPax);

        $inventories = $query->get();
        $itineraries = [];

        $tenantSettings = Tenant::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $inventories->pluck('tenant_id')->filter()->unique())
            ->get(['id', 'settings'])
            ->keyBy('id');

        $carbonDate = Carbon::parse($params->departureDate);
        $today = Carbon::today();
        $depDayOfWeek = strtolower($carbonDate->format('l'));
        $depDayShort = strtolower($carbonDate->format('D'));
        $depDayIso = (int) $carbonDate->format('N');
        $depDayZero = (int) $carbonDate->format('w');

        foreach ($inventories as $inventory) {
            $bookingWindowDays = (int) data_get(
                $tenantSettings->get($inventory->tenant_id)?->settings,
                'flight_booking.minimum_advance_booking_days',
                0
            );
            if ($carbonDate->copy()->startOfDay()->lt($today->copy()->addDays($bookingWindowDays))) {
                continue;
            }

            // Check operating days if specified
            if (! empty($inventory->operating_days)) {
                $opDays = (array) $inventory->operating_days;
                $matchesDay = false;
                foreach ($opDays as $opDay) {
                    $opDayStr = strtolower(trim((string) $opDay));
                    if (
                        $opDayStr === $depDayOfWeek
                        || $opDayStr === $depDayShort
                        || (int) $opDayStr === $depDayIso
                        || (int) $opDayStr === $depDayZero
                    ) {
                        $matchesDay = true;
                        break;
                    }
                }
                if (! $matchesDay) {
                    continue;
                }
            }

            // Standardize Itinerary
            $itinerary = $this->transformInternalInventoryToItinerary($inventory, $params);
            if (! empty($itinerary['fares'])) {
                $itineraries[] = $itinerary;
            }
        }

        return $itineraries;
    }

    /**
     * Search configured external suppliers through SupplierManager.
     */
    protected function searchSuppliers(FlightSearchParams $params): array
    {
        $itineraries = [];

        try {
            $supplierParams = new SupplierSearchParams(
                origin:        $params->origin,
                destination:   $params->destination,
                departureDate: $params->departureDate,
                returnDate:    $params->returnDate,
                cabinClass:    $params->cabinClass,
                adults:        $params->adults,
                children:      $params->children,
                infants:       $params->infants,
                currency:      $params->currency,
            );

            $supplierCodes = $params->supplier ? [$params->supplier] : null;
            $supplierResponse = $this->supplierManager->searchMulti($supplierParams, $supplierCodes);

            if ($supplierResponse->success && ! empty($supplierResponse->offers)) {
                foreach ($supplierResponse->offers as $offer) {
                    $itinerary = $this->transformSupplierOfferToItinerary($offer, $params);
                    if ($itinerary) {
                        $itineraries[] = $itinerary;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Supplier flight search error: ' . $e->getMessage());
        }

        return $itineraries;
    }

    /**
     * Transform internal FlightInventory into standardized Itinerary DTO with aggregated fares.
     */
    protected function transformInternalInventoryToItinerary(FlightInventory $inv, FlightSearchParams $params): array
    {
        $durationMinutes = $this->calculateDurationMinutes($inv->departure_time, $inv->arrival_time);
        $stops = isset($inv->metadata['stops']) ? (int) $inv->metadata['stops'] : 0;
        $originCode = $inv->originAirport?->iata_code ?? $params->origin;
        $destCode = $inv->destinationAirport?->iata_code ?? $params->destination;
        $airlineCode = $inv->airline?->iata_code ?? substr($inv->flight_number, 0, 2);
        $airlineName = $inv->airline?->name ?? 'Airline ' . $airlineCode;

        $depTime = substr((string) $inv->departure_time, 0, 5);
        $arrTime = substr((string) $inv->arrival_time, 0, 5);

        $fares = [];
        $activeFares = $inv->fares->where('status', 'active');

        if ($activeFares->isNotEmpty()) {
            foreach ($activeFares as $fare) {
                // Fare rows describe price/rules, not separate seat buckets. Every
                // cabin and fare family draws from the parent flight inventory.
                if ($inv->available_seats < $params->totalPassengers()) {
                    continue;
                }
                if ($params->cabinClass && strtoupper($fare->cabin_class) !== $params->cabinClass) {
                    continue;
                }

                $fares[] = $this->transformFlightFareToOption($fare, $inv, $params);
            }
        }

        // Fallback: If no explicit FlightFare model rows exist, create default standard fare from inventory
        if (empty($fares)) {
            $pricing = $this->pricingEngine->calculate([
                'supplier_fare' => (float) $inv->base_price,
                'taxes'         => round((float) $inv->base_price * 0.12, 2),
                'supplier_fees' => 150.00,
            ]);

            $fares[] = [
                'fare_id'         => 'INT-FARE-' . $inv->id,
                'fare_family'     => [
                    'code'        => 'STANDARD',
                    'name'        => 'Standard',
                    'description' => 'Regular Economy Fare',
                ],
                'cabin_class'     => strtoupper($inv->cabin_class ?? 'ECONOMY'),
                'seats_available' => (int) $inv->available_seats,
                'baggage'         => [
                    'cabin_kg'    => (int) ($inv->baggage_policy['cabin_kg'] ?? 7),
                    'checkin_kg'  => (int) ($inv->baggage_policy['checkin_kg'] ?? 15),
                    'total_kg'    => ((int) ($inv->baggage_policy['cabin_kg'] ?? 7)) + ((int) ($inv->baggage_policy['checkin_kg'] ?? 15)),
                    'text'        => '7kg cabin + 15kg check-in',
                ],
                'refundability'   => [
                    'is_refundable' => (bool) $inv->is_refundable,
                    'policy'        => $inv->cancellation_policy ?? ($inv->is_refundable ? 'Refundable with standard fee' : 'Non-refundable'),
                    'fee'           => $inv->is_refundable ? 1000.00 : null,
                ],
                'changeability'   => [
                    'is_changeable' => true,
                    'policy'        => $inv->reschedule_policy ?? 'Reschedule allowed with change fee',
                    'fee'           => 500.00,
                ],
                'price'           => [
                    'currency'             => $params->currency,
                    'supplier_fare'        => $pricing['supplier_fare'],
                    'base_fare'            => $pricing['base_fare'],
                    'taxes'                => $pricing['taxes'],
                    'supplier_fees'        => $pricing['supplier_fees'],
                    'platform_markup'      => $pricing['platform_markup'],
                    'tenant_markup'        => $pricing['tenant_markup'],
                    'client_markup'        => $pricing['client_markup'],
                    'convenience_fee'      => $pricing['convenience_fee'],
                    'gst'                  => $pricing['gst'],
                    'discount'             => $pricing['discount'],
                    'final_customer_price' => $pricing['final_customer_price'],
                ],
                'source'          => 'internal',
                'offer_ref'       => $inv->inventory_id,
                'is_cheapest'     => false,
                'is_fastest'      => false,
                'is_best_baggage' => false,
                'is_recommended'  => false,
                'composite_score' => 0.0,
            ];
        }

        return [
            'flight_inventory_id'  => (int) $inv->id,
            'itinerary_id'         => "INT-{$originCode}-{$destCode}-{$airlineCode}-{$inv->flight_number}-" . str_replace(':', '', $depTime),
            'airline'              => [
                'code' => $airlineCode,
                'name' => $airlineName,
                'logo' => $inv->airline?->logo_url ?? null,
            ],
            'flight_number'        => $inv->flight_number,
            'origin'               => [
                'code' => $originCode,
                'name' => $inv->originAirport?->name ?? $originCode,
                'city' => $inv->originAirport?->city ?? $originCode,
            ],
            'destination'          => [
                'code' => $destCode,
                'name' => $inv->destinationAirport?->name ?? $destCode,
                'city' => $inv->destinationAirport?->city ?? $destCode,
            ],
            'departure'            => [
                'date'     => $params->departureDate,
                'time'     => $depTime,
                'datetime' => "{$params->departureDate} {$depTime}:00",
            ],
            'arrival'              => [
                'date'     => $params->departureDate,
                'time'     => $arrTime,
                'datetime' => "{$params->departureDate} {$arrTime}:00",
            ],
            'duration_minutes'     => $durationMinutes,
            'duration'             => $this->formatDuration($durationMinutes),
            'stops'                => $stops,
            'aircraft'             => [
                'model' => $inv->aircraft?->model ?? 'Commercial Aircraft',
                'code'  => $inv->aircraft?->iata_code ?? 'JET',
            ],
            'source'               => 'internal',
            'min_price'            => 0.0,
            'max_price'            => 0.0,
            'currency'             => $params->currency,
            'badges'               => [
                'is_cheapest'     => false,
                'is_fastest'      => false,
                'is_best_baggage' => false,
                'is_recommended'  => false,
            ],
            'best_composite_score' => 0.0,
            'fares'                => $fares,
        ];
    }

    /**
     * Transform FlightFare model into standardized Fare Option.
     */
    protected function transformFlightFareToOption(FlightFare $fare, FlightInventory $inv, FlightSearchParams $params): array
    {
        $cabinKg = (int) ($fare->baggage['cabin_kg'] ?? 7);
        $checkinKg = (int) ($fare->baggage['checkin_kg'] ?? 15);

        return [
            'flight_fare_id'  => (int) $fare->id,
            'flight_inventory_id' => (int) $fare->flight_inventory_id,
            'fare_id'         => (string) $fare->id,
            'fare_family'     => [
                'code'        => $fare->fare_family_code ?? ($fare->fareFamily?->code ?? 'STANDARD'),
                'name'        => $fare->fareFamily?->name ?? ($fare->fare_family_code ?? 'Standard'),
                'description' => $fare->fareFamily?->description ?? null,
            ],
            'cabin_class'     => strtoupper($fare->cabin_class ?? 'ECONOMY'),
            'seats_available' => (int) $inv->available_seats,
            'baggage'         => [
                'cabin_kg'   => $cabinKg,
                'checkin_kg' => $checkinKg,
                'total_kg'   => $cabinKg + $checkinKg,
                'text'       => "{$cabinKg}kg cabin + {$checkinKg}kg check-in",
            ],
            'refundability'   => [
                'is_refundable' => (bool) $fare->is_refundable,
                'policy'        => (bool) $fare->is_refundable ? 'Refundable fare' : 'Non-refundable fare',
                'fee'           => (bool) $fare->is_refundable ? 1000.00 : null,
            ],
            'changeability'   => [
                'is_changeable' => (bool) $fare->is_changeable,
                'policy'        => (bool) $fare->is_changeable ? 'Change allowed' : 'No changes permitted',
                'fee'           => (bool) $fare->is_changeable ? 500.00 : null,
            ],
            'price'           => [
                'currency'             => $params->currency,
                'supplier_fare'        => (float) $fare->supplier_fare,
                'base_fare'            => (float) $fare->base_fare,
                'taxes'                => (float) $fare->taxes,
                'supplier_fees'        => (float) $fare->supplier_fees,
                'platform_markup'      => (float) $fare->platform_markup,
                'tenant_markup'        => (float) $fare->tenant_markup,
                'client_markup'        => (float) $fare->client_markup,
                'convenience_fee'      => (float) $fare->convenience_fee,
                'gst'                  => (float) $fare->gst,
                'discount'             => (float) $fare->discount,
                'final_customer_price' => (float) $fare->final_customer_price,
            ],
            'source'          => 'internal',
            'offer_ref'       => $inv->inventory_id,
            'is_cheapest'     => false,
            'is_fastest'      => false,
            'is_best_baggage' => false,
            'is_recommended'  => false,
            'composite_score' => 0.0,
        ];
    }

    /**
     * Transform external supplier flight offer into standardized Itinerary DTO.
     */
    protected function transformSupplierOfferToItinerary(array $offer, FlightSearchParams $params): ?array
    {
        $airlineCode = $offer['airline']['code'] ?? substr($offer['flight_number'] ?? 'XX100', 0, 2);
        $airlineName = $offer['airline']['name'] ?? 'Airline ' . $airlineCode;
        $originCode = strtoupper($offer['origin'] ?? $params->origin);
        $destCode = strtoupper($offer['destination'] ?? $params->destination);
        $flightNumber = $offer['flight_number'] ?? $airlineCode . '100';

        $depDateTime = $offer['departure_time'] ?? "{$params->departureDate} 08:00:00";
        $arrDateTime = $offer['arrival_time'] ?? "{$params->departureDate} 10:15:00";
        $depTime = substr(explode(' ', $depDateTime)[1] ?? '08:00', 0, 5);
        $arrTime = substr(explode(' ', $arrDateTime)[1] ?? '10:15', 0, 5);

        $durationMinutes = $this->calculateDurationMinutes($depDateTime, $arrDateTime);
        $stops = isset($offer['stops']) ? (int) $offer['stops'] : 0;
        $supplierCode = $offer['supplier_code'] ?? 'SUPPLIER';

        // Calculate Pricing Breakdown via FlightPricingEngine
        $rawPricing = $offer['pricing'] ?? [];
        $supplierFare = (float) ($rawPricing['base_fare'] ?? ($offer['total_fare'] ?? 4500));
        $taxes = (float) ($rawPricing['tax'] ?? round($supplierFare * 0.12, 2));
        $fees = (float) ($rawPricing['fees'] ?? 150.00);

        $pricingBreakdown = $this->pricingEngine->calculate([
            'supplier_fare' => $supplierFare,
            'taxes'         => $taxes,
            'supplier_fees' => $fees,
        ]);

        $cabinKg = (int) ($offer['baggage']['cabin_kg'] ?? 7);
        $checkinKg = (int) ($offer['baggage']['checkin_kg'] ?? 15);
        $isRefundable = (bool) ($offer['is_refundable'] ?? false);
        $isChangeable = (bool) ($offer['is_changeable'] ?? true);

        $fare = [
            'fare_id'         => 'SUP-FARE-' . ($offer['offer_reference'] ?? Str::random(8)),
            'fare_family'     => [
                'code'        => 'STANDARD',
                'name'        => 'Standard',
                'description' => 'Supplier Flight Offer',
            ],
            'cabin_class'     => strtoupper($offer['cabin_class'] ?? $params->cabinClass),
            'seats_available' => (int) ($offer['available_seats'] ?? 9),
            'baggage'         => [
                'cabin_kg'   => $cabinKg,
                'checkin_kg' => $checkinKg,
                'total_kg'   => $cabinKg + $checkinKg,
                'text'       => "{$cabinKg}kg cabin + {$checkinKg}kg check-in",
            ],
            'refundability'   => [
                'is_refundable' => $isRefundable,
                'policy'        => $isRefundable ? 'Refundable according to supplier fare rules' : 'Non-refundable',
                'fee'           => $isRefundable ? 1000.00 : null,
            ],
            'changeability'   => [
                'is_changeable' => $isChangeable,
                'policy'        => $isChangeable ? 'Change allowed with fee' : 'Non-changeable',
                'fee'           => $isChangeable ? 500.00 : null,
            ],
            'price'           => [
                'currency'             => $params->currency,
                'supplier_fare'        => $pricingBreakdown['supplier_fare'],
                'base_fare'            => $pricingBreakdown['base_fare'],
                'taxes'                => $pricingBreakdown['taxes'],
                'supplier_fees'        => $pricingBreakdown['supplier_fees'],
                'platform_markup'      => $pricingBreakdown['platform_markup'],
                'tenant_markup'        => $pricingBreakdown['tenant_markup'],
                'client_markup'        => $pricingBreakdown['client_markup'],
                'convenience_fee'      => $pricingBreakdown['convenience_fee'],
                'gst'                  => $pricingBreakdown['gst'],
                'discount'             => $pricingBreakdown['discount'],
                'final_customer_price' => $pricingBreakdown['final_customer_price'],
            ],
            'source'          => $supplierCode,
            'offer_ref'       => $offer['offer_reference'] ?? null,
            'is_cheapest'     => false,
            'is_fastest'      => false,
            'is_best_baggage' => false,
            'is_recommended'  => false,
            'composite_score' => 0.0,
        ];

        return [
            'itinerary_id'         => "{$supplierCode}-{$originCode}-{$destCode}-{$airlineCode}-{$flightNumber}-" . str_replace(':', '', $depTime),
            'airline'              => [
                'code' => $airlineCode,
                'name' => $airlineName,
                'logo' => null,
            ],
            'flight_number'        => $flightNumber,
            'origin'               => [
                'code' => $originCode,
                'name' => 'Airport ' . $originCode,
                'city' => $originCode,
            ],
            'destination'          => [
                'code' => $destCode,
                'name' => 'Airport ' . $destCode,
                'city' => $destCode,
            ],
            'departure'            => [
                'date'     => $params->departureDate,
                'time'     => $depTime,
                'datetime' => $depDateTime,
            ],
            'arrival'              => [
                'date'     => $params->departureDate,
                'time'     => $arrTime,
                'datetime' => $arrDateTime,
            ],
            'duration_minutes'     => $durationMinutes,
            'duration'             => $this->formatDuration($durationMinutes),
            'stops'                => $stops,
            'aircraft'             => [
                'model' => 'Commercial Jet',
                'code'  => 'JET',
            ],
            'source'               => $supplierCode,
            'min_price'            => $pricingBreakdown['final_customer_price'],
            'max_price'            => $pricingBreakdown['final_customer_price'],
            'currency'             => $params->currency,
            'badges'               => [
                'is_cheapest'     => false,
                'is_fastest'      => false,
                'is_best_baggage' => false,
                'is_recommended'  => false,
            ],
            'best_composite_score' => 0.0,
            'fares'                => [$fare],
        ];
    }

    /**
     * Generate canonical key to identify identical flight itineraries.
     */
    protected function generateItineraryKey(array $itinerary): string
    {
        $origin = $itinerary['origin']['code'];
        $dest = $itinerary['destination']['code'];
        $airline = $itinerary['airline']['code'];
        $flightNo = strtoupper(str_replace(' ', '', $itinerary['flight_number']));
        $depTime = $itinerary['departure']['time'];

        return "{$origin}_{$dest}_{$airline}_{$flightNo}_{$depTime}";
    }

    /**
     * Merge duplicate flight itineraries into one, aggregating distinct fare options.
     */
    protected function mergeItineraries(array $existing, array $incoming): array
    {
        // Mark source as aggregated if coming from different origins
        if ($existing['source'] !== $incoming['source']) {
            $existing['source'] = 'aggregated';
        }

        // Aggregate fares under the single itinerary
        foreach ($incoming['fares'] as $newFare) {
            $duplicateFound = false;
            foreach ($existing['fares'] as $idx => $existingFare) {
                // If same fare family code and cabin class, retain the one with lowest final customer price
                if ($existingFare['fare_family']['code'] === $newFare['fare_family']['code']
                    && $existingFare['cabin_class'] === $newFare['cabin_class']) {
                    $duplicateFound = true;
                    if ($newFare['price']['final_customer_price'] < $existingFare['price']['final_customer_price']) {
                        $existing['fares'][$idx] = $newFare;
                    }
                    break;
                }
            }

            if (! $duplicateFound) {
                $existing['fares'][] = $newFare;
            }
        }

        return $existing;
    }

    /**
     * Integrate with Phase 3 Best Fare Engine (6-Factor Model).
     *
     * Strict Consumer-Centric Scoring Guarantee:
     * Evaluates Price (40%), Duration (25%), Stops (15%), Baggage (10%),
     * Refundability (5%), and Changeability (5%).
     * ZERO commercial margin influence.
     */
    protected function evaluateBestFares(array $allFaresList): ?array
    {
        if (empty($allFaresList)) {
            return null;
        }

        // Convert options to pseudo FlightFare models to feed Phase 3 FlightFareAggregationEngine
        $pseudoFares = collect();

        foreach ($allFaresList as $index => $item) {
            $fare = $item['fare'];
            $itin = $item['itinerary'];

            $pseudoFare = new FlightFare([
                'fare_family_code'     => $fare['fare_family']['code'],
                'cabin_class'          => $fare['cabin_class'],
                'supplier_fare'        => $fare['price']['supplier_fare'],
                'base_fare'            => $fare['price']['base_fare'],
                'final_customer_price' => $fare['price']['final_customer_price'],
                'baggage'              => [
                    'cabin_kg'   => $fare['baggage']['cabin_kg'],
                    'checkin_kg' => $fare['baggage']['checkin_kg'],
                ],
                'is_refundable'        => $fare['refundability']['is_refundable'],
                'is_changeable'        => $fare['changeability']['is_changeable'],
                'metadata'             => ['stops' => $itin['stops']],
            ]);
            $pseudoFare->id = $fare['fare_id'] ?? ($index + 1);

            // Attach pseudo inventory schedule
            $pseudoInventory = new FlightInventory([
                'flight_number'  => $itin['flight_number'],
                'departure_time' => $itin['departure']['time'] . ':00',
                'arrival_time'   => $itin['arrival']['time'] . ':00',
            ]);
            $pseudoInventory->stops = $itin['stops'];

            $pseudoFare->setRelation('inventory', $pseudoInventory);
            $pseudoFare->original_itinerary = $itin;
            $pseudoFare->original_fare = $fare;

            $pseudoFares->push($pseudoFare);
        }

        // Run Phase 3 Aggregation Engine
        $aggregated = $this->aggregationEngine->aggregate($pseudoFares);

        // Map back best fare results
        $cheapest = $aggregated['cheapest_fare'];
        $fastest = $aggregated['fastest_fare'];
        $bestBaggage = $aggregated['best_baggage_fare'];
        $recommended = $aggregated['recommended_fare'];

        // Annotate original items with badges and scores
        $scoredItems = collect($aggregated['all_fares'] ?? []);

        foreach ($allFaresList as &$item) {
            $fareId = $item['fare']['fare_id'];
            $scored = $scoredItems->first(fn ($s) => (string) $s['fare']->id === (string) $fareId);

            if ($scored) {
                $item['fare']['is_cheapest'] = (bool) $scored['is_cheapest'];
                $item['fare']['is_fastest'] = (bool) $scored['is_fastest'];
                $item['fare']['is_best_baggage'] = (bool) $scored['is_best_baggage'];
                $item['fare']['is_recommended'] = (bool) $scored['is_recommended'];
                $item['fare']['composite_score'] = (float) $scored['metrics']['composite_score'];
                $item['fare']['factor_scores'] = $scored['metrics']['factor_scores'];
            }
        }
        unset($item);

        return [
            'cheapest_fare'     => $cheapest ? $this->formatBestFareSummary($cheapest) : null,
            'fastest_fare'      => $fastest ? $this->formatBestFareSummary($fastest) : null,
            'best_baggage_fare' => $bestBaggage ? $this->formatBestFareSummary($bestBaggage) : null,
            'recommended_fare'  => $recommended ? $this->formatBestFareSummary($recommended) : null,
            'summary'           => $aggregated['summary'] ?? [],
        ];
    }

    /**
     * Format best fare highlight summary.
     */
    protected function formatBestFareSummary(FlightFare $fare): array
    {
        $itin = $fare->original_itinerary ?? [];
        $origFare = $fare->original_fare ?? [];

        return [
            'fare_id'              => (string) $fare->id,
            'flight_number'        => $itin['flight_number'] ?? 'Flight',
            'airline'              => $itin['airline'] ?? [],
            'origin'               => $itin['origin'] ?? [],
            'destination'          => $itin['destination'] ?? [],
            'departure_time'       => $itin['departure']['time'] ?? '',
            'arrival_time'         => $itin['arrival']['time'] ?? '',
            'duration'             => $itin['duration'] ?? '',
            'stops'                => $itin['stops'] ?? 0,
            'fare_family'          => $origFare['fare_family'] ?? ['code' => 'STANDARD', 'name' => 'Standard'],
            'final_customer_price' => (float) $fare->final_customer_price,
            'baggage_kg'           => (int) ($origFare['baggage']['total_kg'] ?? 22),
            'is_refundable'        => (bool) $fare->is_refundable,
            'is_changeable'        => (bool) $fare->is_changeable,
        ];
    }

    /**
     * Filter and Sort Itineraries.
     */
    protected function applyFiltersAndSorting(array $itineraries, FlightSearchParams $params): array
    {
        $collection = collect($itineraries);

        // 1. Filter: Airlines
        if (! empty($params->airlines)) {
            $targetAirlines = array_map('strtoupper', (array) $params->airlines);
            $collection = $collection->filter(fn ($it) => in_array(strtoupper($it['airline']['code']), $targetAirlines));
        }

        // 2. Filter: Max Stops
        if ($params->maxStops !== null) {
            $collection = $collection->filter(fn ($it) => $it['stops'] <= $params->maxStops);
        }

        // 3. Filter: Price Range
        if ($params->minPrice !== null) {
            $collection = $collection->filter(fn ($it) => $it['min_price'] >= $params->minPrice);
        }
        if ($params->maxPrice !== null) {
            $collection = $collection->filter(fn ($it) => $it['min_price'] <= $params->maxPrice);
        }

        // 4. Filter: Departure Time Window
        if ($params->departureTimeWindow) {
            $collection = $collection->filter(function ($it) use ($params) {
                $depHour = (int) explode(':', $it['departure']['time'])[0];
                return match ($params->departureTimeWindow) {
                    'morning'   => $depHour >= 5 && $depHour < 12,
                    'afternoon' => $depHour >= 12 && $depHour < 18,
                    'evening'   => $depHour >= 18 && $depHour < 24,
                    'night'     => $depHour >= 0 && $depHour < 5,
                    default     => true,
                };
            });
        }

        // 5. Filter: Refundable Only
        if ($params->refundableOnly === true) {
            $collection = $collection->filter(function ($it) {
                return collect($it['fares'])->contains(fn ($f) => $f['refundability']['is_refundable'] === true);
            });
        }

        // 6. Filter: Baggage Included (> 0 checkin)
        if ($params->baggageIncluded === true) {
            $collection = $collection->filter(function ($it) {
                return collect($it['fares'])->contains(fn ($f) => ($f['baggage']['checkin_kg'] ?? 0) > 0);
            });
        }

        // 7. Filter: Supplier
        if ($params->supplier) {
            $targetSup = strtoupper($params->supplier);
            $collection = $collection->filter(function ($it) use ($targetSup) {
                return strtoupper($it['source']) === $targetSup || $it['source'] === 'aggregated';
            });
        }

        // Sync Itinerary Badges with Fares Badges
        $items = $collection->map(function ($it) {
            $fares = collect($it['fares']);
            $it['badges'] = [
                'is_cheapest'     => $fares->contains('is_cheapest', true),
                'is_fastest'      => $fares->contains('is_fastest', true),
                'is_best_baggage' => $fares->contains('is_best_baggage', true),
                'is_recommended'  => $fares->contains('is_recommended', true),
            ];
            $it['best_composite_score'] = (float) $fares->max('composite_score') ?? 0.0;
            return $it;
        });

        // 8. Sorting
        return match ($params->sortBy) {
            'price_asc'      => $items->sortBy('min_price')->values()->all(),
            'price_desc'     => $items->sortByDesc('min_price')->values()->all(),
            'duration_asc'   => $items->sortBy('duration_minutes')->values()->all(),
            'departure_asc'  => $items->sortBy('departure.time')->values()->all(),
            'departure_desc' => $items->sortByDesc('departure.time')->values()->all(),
            default          => $items->sortByDesc('best_composite_score')->values()->all(), // 'recommended'
        };
    }

    /**
     * Extract filter facets for client frontend filters.
     */
    protected function extractFilterFacets(array $rawItineraries): array
    {
        $airlinesMap = [];
        $minPrice = PHP_INT_MAX;
        $maxPrice = 0.0;
        $stopsCounts = ['direct' => 0, 'one_stop' => 0, 'multi_stop' => 0];

        foreach ($rawItineraries as $it) {
            $code = $it['airline']['code'];
            $name = $it['airline']['name'];
            $price = $it['min_price'];

            if (! isset($airlinesMap[$code])) {
                $airlinesMap[$code] = ['code' => $code, 'name' => $name, 'count' => 0, 'min_price' => $price];
            }
            $airlinesMap[$code]['count']++;
            $airlinesMap[$code]['min_price'] = min($airlinesMap[$code]['min_price'], $price);

            $minPrice = min($minPrice, $price);
            $maxPrice = max($maxPrice, $price);

            if ($it['stops'] === 0) {
                $stopsCounts['direct']++;
            } elseif ($it['stops'] === 1) {
                $stopsCounts['one_stop']++;
            } else {
                $stopsCounts['multi_stop']++;
            }
        }

        return [
            'airlines'     => array_values($airlinesMap),
            'price_range'  => [
                'min' => $minPrice === PHP_INT_MAX ? 0 : $minPrice,
                'max' => $maxPrice,
            ],
            'stops'        => $stopsCounts,
            'time_windows' => [
                ['id' => 'morning',   'label' => 'Morning (05:00 - 11:59)'],
                ['id' => 'afternoon', 'label' => 'Afternoon (12:00 - 17:59)'],
                ['id' => 'evening',   'label' => 'Evening (18:00 - 23:59)'],
                ['id' => 'night',     'label' => 'Night (00:00 - 04:59)'],
            ],
        ];
    }

    /**
     * Calculate flight duration in minutes.
     */
    protected function calculateDurationMinutes(string $dep, string $arr): int
    {
        try {
            $d = strtotime($dep);
            $a = strtotime($arr);
            if ($d && $a) {
                if ($a < $d) {
                    $a += 86400; // Next-day arrival
                }
                return max(1, (int) round(($a - $d) / 60));
            }
        } catch (\Throwable) {}

        return 135; // Default 2h 15m
    }

    /**
     * Format minutes to human-readable string (e.g. 2h 15m).
     */
    protected function formatDuration(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
    }
}
