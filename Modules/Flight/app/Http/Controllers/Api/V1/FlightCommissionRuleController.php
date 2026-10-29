<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Services\Catalog\FlightCatalogTenant;
use Modules\Flight\Models\FlightCommissionLog;
use Modules\Flight\Models\FlightCommissionRule;
use Modules\Flight\Services\Commission\DTOs\CommissionCalculationRequest;
use Modules\Flight\Services\Commission\FlightCommissionEngine;
use Modules\Support\ApiResponse;

class FlightCommissionRuleController extends Controller
{
    public function __construct(
        protected FlightCommissionEngine $engine
    ) {}

    /**
     * List commission rules with filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FlightCommissionRule::query()
            ->with(['supplier', 'airline', 'originAirport', 'destinationAirport', 'fareFamily'])
            ->orderBy('priority', 'desc')
            ->orderBy('id', 'desc');

        if ($tier = $request->query('recipient_tier')) {
            $query->where('recipient_tier', strtoupper($tier));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($airline = $request->query('airline_code')) {
            $query->where('airline_code', strtoupper($airline));
        }

        if ($cabin = $request->query('cabin_class')) {
            $query->where('cabin_class', strtoupper($cabin));
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $perPage = $request->integer('per_page', 20);

        return ApiResponse::pagination($query->paginate($perPage));
    }

    /**
     * Create a new commission rule.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                   => 'required|string|max:255',
            'code'                   => 'nullable|string|max:50',
            'description'            => 'nullable|string|max:1000',
            'recipient_tier'         => 'required|in:SUPER_ADMIN,TENANT,DISTRIBUTOR,AGENT',
            'recipient_id'           => 'nullable|integer',
            'flight_supplier_id'     => 'nullable|integer|exists:flight_suppliers,id',
            'airline_id'             => ['nullable', 'integer', FlightCatalogTenant::exists('flight_airlines')],
            'airline_code'           => 'nullable|string|max:10',
            'origin_airport_id'      => ['nullable', 'integer', FlightCatalogTenant::exists('flight_airports')],
            'destination_airport_id' => ['nullable', 'integer', FlightCatalogTenant::exists('flight_airports')],
            'cabin_class'            => 'nullable|string|max:30',
            'fare_family_id'         => 'nullable|integer|exists:flight_fare_families,id',
            'flight_number'          => 'nullable|string|max:20',
            'calculation_type'       => 'required|in:PERCENTAGE,FIXED,HYBRID',
            'calculation_basis'      => 'nullable|in:BASE_FARE,TOTAL_FARE,PER_BOOKING,PER_PASSENGER',
            'percentage'             => 'nullable|numeric|min:0|max:100',
            'fixed_amount'           => 'nullable|numeric|min:0',
            'min_commission'         => 'nullable|numeric|min:0',
            'max_commission'         => 'nullable|numeric|min:0',
            'currency'               => 'nullable|string|size:3',
            'priority'               => 'nullable|integer|min:0|max:1000',
            'override_parent'        => 'nullable|boolean',
            'is_active'              => 'nullable|boolean',
            'effective_from'         => 'nullable|date',
            'effective_to'           => 'nullable|date',
        ]);

        $data['tenant_id'] = auth()->user()?->tenant_id;
        $data['created_by'] = auth()->id();

        $rule = FlightCommissionRule::create($data);

        return ApiResponse::created($rule->fresh([
            'supplier', 'airline', 'originAirport', 'destinationAirport', 'fareFamily',
        ]));
    }

    /**
     * Show a commission rule.
     */
    public function show(int $id): JsonResponse
    {
        $rule = FlightCommissionRule::with([
            'supplier', 'airline', 'originAirport', 'destinationAirport', 'fareFamily',
        ])->findOrFail($id);

        return ApiResponse::success($rule);
    }

    /**
     * Update an existing commission rule.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $rule = FlightCommissionRule::findOrFail($id);

        $data = $request->validate([
            'name'                   => 'sometimes|required|string|max:255',
            'code'                   => 'nullable|string|max:50',
            'description'            => 'nullable|string|max:1000',
            'recipient_tier'         => 'sometimes|required|in:SUPER_ADMIN,TENANT,DISTRIBUTOR,AGENT',
            'recipient_id'           => 'nullable|integer',
            'flight_supplier_id'     => 'nullable|integer|exists:flight_suppliers,id',
            'airline_id'             => ['nullable', 'integer', FlightCatalogTenant::exists('flight_airlines')],
            'airline_code'           => 'nullable|string|max:10',
            'origin_airport_id'      => ['nullable', 'integer', FlightCatalogTenant::exists('flight_airports')],
            'destination_airport_id' => ['nullable', 'integer', FlightCatalogTenant::exists('flight_airports')],
            'cabin_class'            => 'nullable|string|max:30',
            'fare_family_id'         => 'nullable|integer|exists:flight_fare_families,id',
            'flight_number'          => 'nullable|string|max:20',
            'calculation_type'       => 'sometimes|required|in:PERCENTAGE,FIXED,HYBRID',
            'calculation_basis'      => 'nullable|in:BASE_FARE,TOTAL_FARE,PER_BOOKING,PER_PASSENGER',
            'percentage'             => 'nullable|numeric|min:0|max:100',
            'fixed_amount'           => 'nullable|numeric|min:0',
            'min_commission'         => 'nullable|numeric|min:0',
            'max_commission'         => 'nullable|numeric|min:0',
            'currency'               => 'nullable|string|size:3',
            'priority'               => 'nullable|integer|min:0|max:1000',
            'override_parent'        => 'nullable|boolean',
            'is_active'              => 'nullable|boolean',
            'effective_from'         => 'nullable|date',
            'effective_to'           => 'nullable|date',
        ]);

        $data['updated_by'] = auth()->id();
        $rule->update($data);

        return ApiResponse::success($rule->fresh([
            'supplier', 'airline', 'originAirport', 'destinationAirport', 'fareFamily',
        ]));
    }

    /**
     * Delete a commission rule (soft delete).
     */
    public function destroy(int $id): JsonResponse
    {
        $rule = FlightCommissionRule::findOrFail($id);
        $rule->delete();

        return ApiResponse::success(['message' => 'Commission rule deleted successfully.']);
    }

    /**
     * Deterministically calculate commissions across hierarchy for a flight booking context.
     *
     * POST /api/v1/flight/commission/calculate
     */
    public function calculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'base_fare'              => 'required|numeric|min:0',
            'taxes'                  => 'nullable|numeric|min:0',
            'total_fare'             => 'nullable|numeric|min:0',
            'passengers_count'       => 'nullable|integer|min:1',
            'flight_supplier_id'     => 'nullable|integer',
            'supplier_id'            => 'nullable|integer',
            'supplier_code'          => 'nullable|string',
            'airline_id'             => 'nullable|integer',
            'airline_code'           => 'nullable|string|max:10',
            'origin_airport_id'      => 'nullable|integer',
            'destination_airport_id' => 'nullable|integer',
            'cabin_class'            => 'nullable|string|max:30',
            'fare_family_id'         => 'nullable|integer',
            'flight_number'          => 'nullable|string|max:20',
            'tenant_id'              => 'nullable|integer',
            'distributor_id'         => 'nullable|integer',
            'agent_id'               => 'nullable|integer',
            'booking_reference'      => 'nullable|string|max:50',
            'currency'               => 'nullable|string|size:3',
            'record_audit'           => 'nullable|boolean',
        ]);

        $dto = CommissionCalculationRequest::fromArray($data);
        $recordAudit = filter_var($request->input('record_audit', true), FILTER_VALIDATE_BOOLEAN);

        $result = $this->engine->calculate($dto, $recordAudit);

        return ApiResponse::success($result->toArray());
    }

    /**
     * View audit history logs of calculated commissions.
     *
     * GET /api/v1/flight/commission/logs
     */
    public function logs(Request $request): JsonResponse
    {
        $query = FlightCommissionLog::query()
            ->with(['rule', 'tenant'])
            ->latest('created_at');

        if ($ref = $request->query('booking_reference')) {
            $query->where('booking_reference', $ref);
        }

        if ($tier = $request->query('recipient_tier')) {
            $query->where('recipient_tier', strtoupper($tier));
        }

        if ($ruleId = $request->query('flight_commission_rule_id')) {
            $query->where('flight_commission_rule_id', $ruleId);
        }

        $perPage = $request->integer('per_page', 25);

        return ApiResponse::pagination($query->paginate($perPage));
    }
}
