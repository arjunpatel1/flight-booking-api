<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Models\FlightFare;
use Modules\Flight\Models\FlightFareRule;
use Modules\Flight\Models\FlightInventory;
use Modules\Flight\Services\Pricing\FlightFareAggregationEngine;
use Modules\Flight\Services\Pricing\FlightPricingEngine;
use Modules\Support\ApiResponse;

class FlightFareController extends Controller
{
    public function __construct(
        protected FlightPricingEngine $pricingEngine,
        protected FlightFareAggregationEngine $aggregationEngine
    ) {}

    /** Resolve a displayed customer fare to the saved fare and inventory IDs. */
    public function resolveBookingOption(Request $request): JsonResponse
    {
        $data = $request->validate([
            'flight_number' => ['required', 'string', 'max:20'],
            'origin_code' => ['nullable', 'string', 'size:3'],
            'destination_code' => ['nullable', 'string', 'size:3'],
            'departure_time' => ['nullable', 'date_format:H:i'],
            'fare_family_code' => ['required', 'string', 'max:50'],
            'cabin_class' => ['required', 'string', 'max:30'],
        ]);

        $flightNumber = strtoupper(trim($data['flight_number']));
        $originCode = strtoupper(trim((string) ($data['origin_code'] ?? '')));
        $destinationCode = strtoupper(trim((string) ($data['destination_code'] ?? '')));
        $departureTime = trim((string) ($data['departure_time'] ?? ''));
        $cabinClass = strtoupper(trim($data['cabin_class']));
        $familyCode = strtoupper(trim($data['fare_family_code']));

        $fares = FlightFare::query()
            ->with(['inventory', 'fareFamily'])
            ->where('status', 'active')
            ->where('cabin_class', $cabinClass)
            ->whereHas('inventory', function ($inventory) use ($flightNumber, $originCode, $destinationCode, $departureTime): void {
                $inventory->where('flight_number', $flightNumber)
                    ->where('status', 'active');

                if ($departureTime !== '') {
                    $inventory->where('departure_time', $departureTime . ':00');
                }

                if ($originCode !== '') {
                    $inventory->whereHas('originAirport', fn ($airport) => $airport->where('iata_code', $originCode));
                }

                if ($destinationCode !== '') {
                    $inventory->whereHas('destinationAirport', fn ($airport) => $airport->where('iata_code', $destinationCode));
                }
            })
            ->get();

        $fare = $fares->first(function (FlightFare $candidate) use ($familyCode): bool {
            $codes = [
                $candidate->fare_family_code,
                $candidate->fareFamily?->code,
                $candidate->fareFamily?->name,
            ];

            return collect($codes)->contains(fn ($code) => strtoupper(trim((string) $code)) === $familyCode);
        });
        if (! $fare) {
            $normalizeCode = fn ($code) => preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $code)));
            $normalizedFamilyCode = $normalizeCode($familyCode);
            $normalizedMatches = $fares->filter(function (FlightFare $candidate) use ($normalizeCode, $normalizedFamilyCode): bool {
                return collect([
                    $candidate->fare_family_code,
                    $candidate->fareFamily?->code,
                    $candidate->fareFamily?->name,
                ])->contains(fn ($code) => $normalizeCode($code) === $normalizedFamilyCode);
            });
            if ($normalizedMatches->count() === 1) {
                $fare = $normalizedMatches->first();
            }
        }
        if (! $fare) {
            $normalizeCode = fn ($code) => preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $code)));
            $normalizedFamilyCode = $normalizeCode($familyCode);
            $prefixMatches = $fares->filter(fn (FlightFare $candidate) => str_starts_with(
                $normalizeCode($candidate->fare_family_code),
                $normalizedFamilyCode,
            ));
            if ($prefixMatches->count() === 1) {
                $fare = $prefixMatches->first();
            }
        }
        if (! $fare && $fares->count() === 1) {
            $fare = $fares->first();
        }

        if (! $fare || ! $fare->inventory) {
            return ApiResponse::errors(
                ['code' => 'FLIGHT_FARE_NOT_FOUND'],
                'No active saved fare matches this flight, route, cabin, and fare family.',
                422
            );
        }

        return ApiResponse::success([
            'flight_inventory_id' => $fare->flight_inventory_id,
            'flight_fare_id' => $fare->id,
            'available_seats' => (int) $fare->inventory->available_seats,
            'total_seats' => (int) $fare->inventory->total_seats,
        ]);
    }

    /**
     * List flight fares.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FlightFare::query()
            ->with([
                'inventory.airline',
                'inventory.originAirport',
                'inventory.destinationAirport',
                'fareFamily',
                'fareRule',
            ]);

        if ($request->boolean('available_only', false)) {
            $query->available();
        }

        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        if ($inventoryId = $request->query('flight_inventory_id')) {
            $query->where('flight_inventory_id', $inventoryId);
        }

        if ($fareFamily = $request->query('fare_family_code')) {
            $query->where('fare_family_code', strtoupper($fareFamily));
        }

        if ($cabin = $request->query('cabin_class')) {
            $query->where('cabin_class', strtoupper($cabin));
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search', $request->query('query'))) {
            $query->where(function ($q) use ($search) {
                $q->whereLike('fare_family_code', "%{$search}%")
                    ->orWhereLike('fare_basis_code', "%{$search}%")
                    ->orWhereHas('inventory', function ($inv) use ($search) {
                        $inv->whereLike('flight_number', "%{$search}%")
                            ->orWhereLike('inventory_id', "%{$search}%");
                    });
            });
        }

        $perPage = $request->integer('per_page', 20);

        return ApiResponse::pagination($query->latest()->paginate($perPage));
    }

    /**
     * Store a new flight fare.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'flight_inventory_id' => ['required', 'exists:flight_inventories,id'],
            'fare_family_id' => ['nullable', 'exists:flight_fare_families,id'],
            'fare_family_code' => ['required', 'string', 'max:50'],
            'fare_basis_code' => ['nullable', 'string', 'max:50'],
            'cabin_class' => ['required', 'string', Rule::exists('flight_cabin_classes', 'code')->where('is_active', true)],
            'currency' => ['nullable', 'string', 'max:3'],
            'supplier_code' => ['nullable', 'string', 'max:50'],

            // Pricing Pipeline Inputs
            'supplier_fare' => ['required', 'numeric', 'min:0'],
            'pricing_rules_adjustment' => ['nullable', 'numeric'],
            'taxes' => ['nullable', 'numeric', 'min:0'],
            'supplier_fees' => ['nullable', 'numeric', 'min:0'],
            'platform_markup' => ['nullable', 'numeric', 'min:0'],
            'platform_markup_type' => ['nullable', 'string', 'in:fixed,percentage'],
            'tenant_markup' => ['nullable', 'numeric', 'min:0'],
            'tenant_markup_type' => ['nullable', 'string', 'in:fixed,percentage'],
            'client_markup' => ['nullable', 'numeric', 'min:0'],
            'client_markup_type' => ['nullable', 'string', 'in:fixed,percentage'],
            'convenience_fee' => ['nullable', 'numeric', 'min:0'],
            'gst' => ['nullable', 'numeric', 'min:0'],
            'gst_rate' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],

            // Conditions
            'baggage' => ['nullable', 'array'],
            'is_refundable' => ['nullable', 'boolean'],
            'is_changeable' => ['nullable', 'boolean'],
            'total_seats' => ['required', 'integer', 'min:1'],
            'available_seats' => ['required', 'integer', 'min:0', 'lte:total_seats'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:active,expired,sold_out,suspended'],
            'metadata' => ['nullable', 'array'],

            // Fare Rules (Optional payload)
            'rules' => ['nullable', 'array'],
            'rules.cancellation_policy' => ['nullable', 'string'],
            'rules.refund_policy' => ['nullable', 'string'],
            'rules.reschedule_policy' => ['nullable', 'string'],
            'rules.baggage_policy' => ['nullable', 'string'],
            'rules.advance_purchase_days' => ['nullable', 'integer', 'min:0'],
            'rules.min_stay_days' => ['nullable', 'integer', 'min:0'],
            'rules.max_stay_days' => ['nullable', 'integer', 'min:0'],
            'rules.rule_details' => ['nullable', 'array'],
        ]);

        $inventory = FlightInventory::findOrFail($validated['flight_inventory_id']);
        $tenantId = $request->user()?->tenant_id
            ?? ($request->header('X-Tenant-ID') ? (int) $request->header('X-Tenant-ID') : null)
            ?? (int) $inventory->tenant_id;
        if ((int) $inventory->tenant_id !== (int) $tenantId) {
            return ApiResponse::errors(['flight_inventory_id' => 'The selected inventory belongs to a different tenant.'], 'Flight inventory tenant mismatch.', 422);
        }

        // Calculate Pricing Breakdown via Pipeline
        $pricing = $this->pricingEngine->calculate($validated);

        // Assign computed pipeline fields
        $validated['base_fare'] = $pricing['base_fare'];
        $validated['pricing_rules_adjustment'] = $pricing['pricing_rules_adjustment'];
        $validated['taxes'] = $pricing['taxes'];
        $validated['supplier_fees'] = $pricing['supplier_fees'];
        $validated['platform_markup'] = $pricing['platform_markup'];
        $validated['tenant_markup'] = $pricing['tenant_markup'];
        $validated['client_markup'] = $pricing['client_markup'];
        $validated['convenience_fee'] = $pricing['convenience_fee'];
        $validated['gst'] = $pricing['gst'];
        $validated['discount'] = $pricing['discount'];
        $validated['final_customer_price'] = $pricing['final_customer_price'];

        $rulesData = $validated['rules'] ?? null;
        unset($validated['rules'], $validated['platform_markup_type'], $validated['tenant_markup_type'], $validated['client_markup_type'], $validated['gst_rate']);

        $validated['cabin_class'] = strtoupper($validated['cabin_class']);
        $validated['fare_family_code'] = strtoupper($validated['fare_family_code']);
        $validated['currency'] = strtoupper($validated['currency'] ?? 'USD');
        $validated['tenant_id'] = $tenantId;

        $fare = FlightFare::create($validated);

        // Create fare rule if supplied or default from fare family
        if ($rulesData) {
            $rulesData['tenant_id'] = $tenantId;
            $rulesData['flight_fare_id'] = $fare->id;
            FlightFareRule::create($rulesData);
        }

        $fare->load(['inventory.airline', 'inventory.originAirport', 'inventory.destinationAirport', 'fareFamily', 'fareRule']);

        return ApiResponse::created($fare, message: 'Flight fare created successfully');
    }

    /**
     * Show flight fare details.
     */
    public function show(int $id): JsonResponse
    {
        $fare = FlightFare::with([
            'inventory.airline',
            'inventory.originAirport',
            'inventory.destinationAirport',
            'inventory.aircraft',
            'fareFamily',
            'fareRule',
        ])->findOrFail($id);

        $breakdown = $this->pricingEngine->calculate([
            'supplier_fare' => (float) $fare->supplier_fare,
            'pricing_rules_adjustment' => (float) $fare->pricing_rules_adjustment,
            'taxes' => (float) $fare->taxes,
            'supplier_fees' => (float) $fare->supplier_fees,
            'platform_markup' => (float) $fare->platform_markup,
            'tenant_markup' => (float) $fare->tenant_markup,
            'client_markup' => (float) $fare->client_markup,
            'convenience_fee' => (float) $fare->convenience_fee,
            'gst' => (float) $fare->gst,
            'discount' => (float) $fare->discount,
        ]);

        return ApiResponse::success([
            'fare' => $fare,
            'pricing_breakdown' => $breakdown,
        ]);
    }

    /**
     * Update flight fare.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $fare = FlightFare::findOrFail($id);

        $validated = $request->validate([
            'flight_inventory_id' => ['sometimes', 'exists:flight_inventories,id'],
            'fare_family_id' => ['nullable', 'exists:flight_fare_families,id'],
            'fare_family_code' => ['sometimes', 'string', 'max:50'],
            'fare_basis_code' => ['nullable', 'string', 'max:50'],
            'cabin_class' => ['sometimes', 'string', Rule::exists('flight_cabin_classes', 'code')->where('is_active', true)],
            'currency' => ['nullable', 'string', 'max:3'],
            'supplier_code' => ['nullable', 'string', 'max:50'],

            'supplier_fare' => ['sometimes', 'numeric', 'min:0'],
            'pricing_rules_adjustment' => ['nullable', 'numeric'],
            'taxes' => ['nullable', 'numeric', 'min:0'],
            'supplier_fees' => ['nullable', 'numeric', 'min:0'],
            'platform_markup' => ['nullable', 'numeric', 'min:0'],
            'platform_markup_type' => ['nullable', 'string', 'in:fixed,percentage'],
            'tenant_markup' => ['nullable', 'numeric', 'min:0'],
            'tenant_markup_type' => ['nullable', 'string', 'in:fixed,percentage'],
            'client_markup' => ['nullable', 'numeric', 'min:0'],
            'client_markup_type' => ['nullable', 'string', 'in:fixed,percentage'],
            'convenience_fee' => ['nullable', 'numeric', 'min:0'],
            'gst' => ['nullable', 'numeric', 'min:0'],
            'gst_rate' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],

            'baggage' => ['nullable', 'array'],
            'is_refundable' => ['nullable', 'boolean'],
            'is_changeable' => ['nullable', 'boolean'],
            'total_seats' => ['sometimes', 'integer', 'min:1'],
            'available_seats' => ['sometimes', 'integer', 'min:0'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:active,expired,sold_out,suspended'],
            'metadata' => ['nullable', 'array'],

            'rules' => ['nullable', 'array'],
        ]);

        // Recompute pricing if price components are updated
        $mergedPricingInputs = array_merge([
            'supplier_fare' => $fare->supplier_fare,
            'pricing_rules_adjustment' => $fare->pricing_rules_adjustment,
            'taxes' => $fare->taxes,
            'supplier_fees' => $fare->supplier_fees,
            'platform_markup' => $fare->platform_markup,
            'tenant_markup' => $fare->tenant_markup,
            'client_markup' => $fare->client_markup,
            'convenience_fee' => $fare->convenience_fee,
            'gst' => $fare->gst,
            'discount' => $fare->discount,
        ], $validated);

        $pricing = $this->pricingEngine->calculate($mergedPricingInputs);

        $validated['base_fare'] = $pricing['base_fare'];
        $validated['pricing_rules_adjustment'] = $pricing['pricing_rules_adjustment'];
        $validated['taxes'] = $pricing['taxes'];
        $validated['supplier_fees'] = $pricing['supplier_fees'];
        $validated['platform_markup'] = $pricing['platform_markup'];
        $validated['tenant_markup'] = $pricing['tenant_markup'];
        $validated['client_markup'] = $pricing['client_markup'];
        $validated['convenience_fee'] = $pricing['convenience_fee'];
        $validated['gst'] = $pricing['gst'];
        $validated['discount'] = $pricing['discount'];
        $validated['final_customer_price'] = $pricing['final_customer_price'];

        $rulesData = $validated['rules'] ?? null;
        unset($validated['rules'], $validated['platform_markup_type'], $validated['tenant_markup_type'], $validated['client_markup_type'], $validated['gst_rate']);

        if (isset($validated['cabin_class'])) {
            $validated['cabin_class'] = strtoupper($validated['cabin_class']);
        }
        if (isset($validated['fare_family_code'])) {
            $validated['fare_family_code'] = strtoupper($validated['fare_family_code']);
        }

        $fare->update($validated);

        if ($rulesData) {
            $rulesData['tenant_id'] = $fare->tenant_id;
            FlightFareRule::updateOrCreate(
                ['flight_fare_id' => $fare->id],
                $rulesData
            );
        }

        $fare->load(['inventory.airline', 'inventory.originAirport', 'inventory.destinationAirport', 'fareFamily', 'fareRule']);

        return ApiResponse::success($fare, message: 'Flight fare updated successfully');
    }

    /**
     * Delete flight fare.
     */
    public function destroy(int $id): JsonResponse
    {
        $fare = FlightFare::findOrFail($id);
        $fare->delete();

        return ApiResponse::success(null, message: 'Flight fare deleted successfully');
    }

    /**
     * Calculate price breakdown on-the-fly for any inputs.
     * Supplier Fare -> Pricing Rules -> Platform Markup -> Tenant Markup -> Client Markup -> Convenience Fee -> GST -> Discount -> Final Customer Price
     */
    public function calculatePrice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_fare' => ['required', 'numeric', 'min:0'],
            'pricing_rules_adjustment' => ['nullable', 'numeric'],
            'taxes' => ['nullable', 'numeric', 'min:0'],
            'supplier_fees' => ['nullable', 'numeric', 'min:0'],
            'platform_markup' => ['nullable', 'numeric', 'min:0'],
            'platform_markup_type' => ['nullable', 'string', 'in:fixed,percentage'],
            'tenant_markup' => ['nullable', 'numeric', 'min:0'],
            'tenant_markup_type' => ['nullable', 'string', 'in:fixed,percentage'],
            'client_markup' => ['nullable', 'numeric', 'min:0'],
            'client_markup_type' => ['nullable', 'string', 'in:fixed,percentage'],
            'convenience_fee' => ['nullable', 'numeric', 'min:0'],
            'gst' => ['nullable', 'numeric', 'min:0'],
            'gst_rate' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $breakdown = $this->pricingEngine->calculate($validated);

        return ApiResponse::success($breakdown);
    }

    /**
     * Check fare availability.
     */
    public function availability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'flight_inventory_id' => ['sometimes', 'exists:flight_inventories,id'],
            'cabin_class' => ['sometimes', 'string', Rule::exists('flight_cabin_classes', 'code')->where('is_active', true)],
            'fare_family_code' => ['sometimes', 'string'],
            'seats_needed' => ['sometimes', 'integer', 'min:1'],
        ]);

        $seatsNeeded = (int) ($validated['seats_needed'] ?? 1);

        // Fare inventory is flight-wide. Fare rows carry pricing and fare-rule
        // metadata; their legacy seat counters must not gate availability.
        $query = FlightFare::query()->where('status', 'active');

        if (isset($validated['flight_inventory_id'])) {
            $query->where('flight_inventory_id', $validated['flight_inventory_id']);
        }
        if (isset($validated['cabin_class'])) {
            $query->where('cabin_class', strtoupper($validated['cabin_class']));
        }
        if (isset($validated['fare_family_code'])) {
            $query->where('fare_family_code', strtoupper($validated['fare_family_code']));
        }

        $matchingFares = $query->with(['inventory.airline', 'fareFamily', 'fareRule'])
            ->whereHas('inventory', fn ($inventory) => $inventory
                ->where('status', 'active')
                ->where('available_seats', '>=', $seatsNeeded))
            ->get();

        return ApiResponse::success([
            'is_available' => $matchingFares->isNotEmpty(),
            'requested_seats' => $seatsNeeded,
            'matching_fares' => $matchingFares,
            'total_options' => $matchingFares->count(),
        ]);
    }

    /**
     * Best Fare Engine & Fare Aggregation endpoint.
     * Evaluates:
     * - cheapestFare
     * - fastestFare
     * - bestBaggageFare
     * - recommendedFare
     */
    public function bestFares(Request $request): JsonResponse
    {
        $query = FlightFare::query()
            ->available()
            ->with([
                'inventory.airline',
                'inventory.originAirport',
                'inventory.destinationAirport',
                'fareFamily',
                'fareRule',
            ]);

        if ($inventoryId = $request->query('flight_inventory_id')) {
            $query->where('flight_inventory_id', $inventoryId);
        }

        if ($originId = $request->query('origin_airport_id')) {
            $query->whereHas('inventory', fn ($q) => $q->where('origin_airport_id', $originId));
        }

        if ($destinationId = $request->query('destination_airport_id')) {
            $query->whereHas('inventory', fn ($q) => $q->where('destination_airport_id', $destinationId));
        }

        if ($cabin = $request->query('cabin_class')) {
            $query->where('cabin_class', strtoupper($cabin));
        }

        $fares = $query->get();

        $aggregated = $this->aggregationEngine->aggregate($fares);

        return ApiResponse::success($aggregated);
    }

    /**
     * Get or set rules for a fare.
     */
    public function rules(Request $request, int $id): JsonResponse
    {
        $fare = FlightFare::findOrFail($id);

        if ($request->isMethod('get')) {
            $rule = $fare->fareRule ?: new FlightFareRule(['flight_fare_id' => $fare->id]);
            return ApiResponse::success($rule);
        }

        $validated = $request->validate([
            'cancellation_policy' => ['nullable', 'string'],
            'refund_policy' => ['nullable', 'string'],
            'reschedule_policy' => ['nullable', 'string'],
            'baggage_policy' => ['nullable', 'string'],
            'advance_purchase_days' => ['nullable', 'integer', 'min:0'],
            'min_stay_days' => ['nullable', 'integer', 'min:0'],
            'max_stay_days' => ['nullable', 'integer', 'min:0'],
            'rule_details' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['tenant_id'] = $fare->tenant_id;

        $rule = FlightFareRule::updateOrCreate(
            ['flight_fare_id' => $fare->id],
            $validated
        );

        return ApiResponse::success($rule, message: 'Fare rules updated successfully');
    }
}
