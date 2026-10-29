<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Services\Catalog\FlightCatalogTenant;
use Modules\Flight\Enums\HoldStatus;
use Modules\Flight\Models\FlightHold;
use Modules\Flight\Services\Hold\FlightHoldService;
use Modules\Saas\Models\Tenant;
use Modules\Support\ApiResponse;

class FlightHoldController extends Controller
{
    public function __construct(
        protected FlightHoldService $holdService
    ) {}

    protected function resolveTenant(Request $request): ?Tenant
    {
        $tenantId = $request->user()?->tenant_id ?? auth()->user()?->tenant_id;
        if (! $tenantId && $request->hasHeader('X-Tenant-ID')) {
            $tenantId = (int) $request->header('X-Tenant-ID');
        }
        if (! $tenantId && $request->filled('tenant_id')) {
            $tenantId = (int) $request->input('tenant_id');
        }

        if ($tenantId) {
            return Tenant::find($tenantId);
        }

        return null;
    }

    /**
     * Price Revalidation endpoint.
     * POST /api/v1/flight/price-check
     */
    public function priceCheck(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fare_id'        => 'required_without:flight_fare_id|integer',
            'flight_fare_id' => 'nullable|integer',
            'expected_price' => 'nullable|numeric|min:0',
            'quantity'       => 'nullable|integer|min:1|max:9',
            'departure_date' => 'nullable|date_format:Y-m-d',
            'cabin_class'    => ['nullable', 'string', FlightCatalogTenant::activeCabinClass()],
        ]);

        try {
            $tenant = $this->resolveTenant($request);
            $result = $this->holdService->revalidatePrice($validated, $tenant);

            if (! $result['available']) {
                return ApiResponse::errors(
                    $result,
                    $result['message'] ?? 'Fare is no longer available.',
                    422
                );
            }

            return ApiResponse::success($result, 'Flight price verified successfully.');
        } catch (Exception $e) {
            return ApiResponse::errors(null, 'Price validation failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Inventory Revalidation check.
     * POST /api/v1/flight/inventory/check
     */
    public function inventoryCheck(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inventory_id'        => 'required_without:flight_inventory_id|integer',
            'flight_inventory_id' => 'nullable|integer',
            'cabin_class'         => ['nullable', 'string', FlightCatalogTenant::activeCabinClass()],
            'quantity'            => 'nullable|integer|min:1|max:9',
            'departure_date'      => 'nullable|date_format:Y-m-d',
            'holder_type'         => 'nullable|string|in:central,tenant,distributor,agent,b2c',
            'holder_id'           => 'nullable|integer',
        ]);

        try {
            $tenant = $this->resolveTenant($request);
            $result = $this->holdService->revalidateInventory($validated, $tenant);

            if (! $result['available']) {
                return ApiResponse::errors(
                    $result,
                    $result['message'] ?? 'Flight inventory insufficient.',
                    422
                );
            }

            return ApiResponse::success($result, 'Flight inventory is available.');
        } catch (Exception $e) {
            return ApiResponse::errors(null, 'Inventory check failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create temporary inventory hold with distributed locking and idempotency.
     * POST /api/v1/flight/holds
     */
    public function createHold(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inventory_id'        => 'required_without:flight_inventory_id|integer',
            'flight_inventory_id' => 'nullable|integer',
            'fare_id'             => 'nullable|integer',
            'flight_fare_id'      => 'nullable|integer',
            'cabin_class'         => ['nullable', 'string', FlightCatalogTenant::activeCabinClass()],
            'quantity'            => 'nullable|integer|min:1|max:9',
            'departure_date'      => 'nullable|date_format:Y-m-d',
            'supplier_code'       => 'nullable|string',
            'reference'           => 'nullable|string|max:100',
            'idempotency_key'     => 'nullable|string|max:100',
            'session_token'       => 'nullable|string|max:100',
        ]);

        try {
            $tenant = $this->resolveTenant($request);
            $user = $request->user();

            $hold = $this->holdService->createHold($validated, $tenant, $user);

            return ApiResponse::success([
                'hold_id'           => $hold->hold_id,
                'id'                => $hold->id,
                'status'            => $hold->status->value,
                'quantity'          => $hold->quantity,
                'cabin_class'       => $hold->cabin_class,
                'expires_at'        => $hold->expires_at->toIso8601String(),
                'remaining_seconds' => $hold->remaining_seconds,
                'reference'         => $hold->reference,
                'flight_number'     => $hold->meta['flight_number'] ?? null,
                'created_at'        => $hold->created_at->toIso8601String(),
            ], 'Inventory hold created successfully.', 201);
        } catch (Exception $e) {
            return ApiResponse::errors(null, 'Hold creation failed: ' . $e->getMessage(), 422);
        }
    }

    /**
     * Get hold details and remaining TTL.
     * GET /api/v1/flight/holds/{id}
     */
    public function getHold(Request $request, string $identifier): JsonResponse
    {
        try {
            $hold = FlightHold::with(['flightInventory', 'flightFare.fareFamily', 'user'])
                ->where('hold_id', $identifier)
                ->orWhere('id', $identifier)
                ->firstOrFail();

            if ($hold->status === HoldStatus::ACTIVE && $hold->expires_at->isPast()) {
                $this->holdService->releaseHold($hold->id, 'TTL Expiry');
                $hold->update(['status' => HoldStatus::EXPIRED]);
                $hold->refresh();
            }

            return ApiResponse::success([
                'hold_id'           => $hold->hold_id,
                'id'                => $hold->id,
                'status'            => $hold->status->value,
                'quantity'          => $hold->quantity,
                'cabin_class'       => $hold->cabin_class,
                'expires_at'        => $hold->expires_at->toIso8601String(),
                'remaining_seconds' => $hold->remaining_seconds,
                'is_active'         => $hold->isActive(),
                'is_expired'        => $hold->isExpired(),
                'reference'         => $hold->reference,
                'meta'              => $hold->meta,
                'flight'            => [
                    'flight_number' => $hold->flightInventory?->flight_number,
                    'origin'        => $hold->flightInventory?->originAirport?->iata_code,
                    'destination'   => $hold->flightInventory?->destinationAirport?->iata_code,
                ],
                'fare'              => [
                    'fare_code'     => $hold->flightFare?->fare_code,
                    'price'         => $hold->flightFare?->final_price,
                    'fare_family'   => $hold->flightFare?->fareFamily?->name,
                ],
            ], 'Hold retrieved successfully.');
        } catch (Exception $e) {
            return ApiResponse::errors(null, 'Hold not found: ' . $e->getMessage(), 404);
        }
    }

    /**
     * Release hold and return seats to inventory.
     * POST /api/v1/flight/holds/{identifier}/release
     */
    public function releaseHold(Request $request, string $identifier): JsonResponse
    {
        try {
            $reason = $request->input('reason', 'User released hold');
            $user = $request->user();

            $hold = $this->holdService->releaseHold($identifier, $reason, $user?->id);

            return ApiResponse::success([
                'hold_id' => $hold->hold_id,
                'status'  => $hold->status->value,
                'message' => 'Seats released and restored to available inventory.',
            ], 'Hold released successfully.');
        } catch (Exception $e) {
            return ApiResponse::errors(null, 'Failed to release hold: ' . $e->getMessage(), 422);
        }
    }

    /**
     * List holds for admin / agent overview.
     * GET /api/v1/flight/holds
     */
    public function indexHolds(Request $request): JsonResponse
    {
        $tenant = $this->resolveTenant($request);

        $query = FlightHold::with(['flightInventory', 'flightFare.fareFamily', 'user'])
            ->forTenant($tenant?->id)
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->input('status')));
        }

        $holds = $query->paginate($request->input('per_page', 20));

        return ApiResponse::success($holds, 'Flight holds retrieved successfully.');
    }

    /**
     * Create an immutable Fare Quote.
     * POST /api/v1/flight/quotes
     */
    public function createQuote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fare_id'         => 'required_without:flight_fare_id|integer',
            'flight_fare_id'  => 'nullable|integer',
            'hold_id'         => 'nullable',
            'quantity'        => 'nullable|integer|min:1|max:9',
            'expected_price'  => 'nullable|numeric|min:0',
            'departure_date'  => 'nullable|date_format:Y-m-d',
            'idempotency_key' => 'nullable|string|max:100',
            'search_context'  => 'nullable|array',
        ]);

        try {
            $tenant = $this->resolveTenant($request);
            $user = $request->user();

            $quote = $this->holdService->createQuote($validated, $tenant, $user);

            return ApiResponse::success([
                'quote_id'          => $quote->quote_id,
                'id'                => $quote->id,
                'hold_id'           => $quote->hold_id,
                'hold_reference'    => $quote->hold_reference,
                'customer_price'    => $quote->customer_price,
                'currency'          => $quote->currency,
                'selected_itinerary'=> $quote->selected_itinerary,
                'fare_details'      => $quote->fare_details,
                'pricing_breakdown' => $quote->pricing_breakdown,
                'status'            => $quote->status,
                'expires_at'        => $quote->expires_at->toIso8601String(),
                'remaining_seconds' => $quote->remaining_seconds,
            ], 'Fare quote created successfully.', 201);
        } catch (Exception $e) {
            return ApiResponse::errors(null, 'Quote creation failed: ' . $e->getMessage(), 422);
        }
    }

    /**
     * Get Fare Quote by ID.
     * GET /api/v1/flight/quotes/{identifier}
     */
    public function getQuote(Request $request, string $identifier): JsonResponse
    {
        try {
            $quote = $this->holdService->getQuote($identifier);

            return ApiResponse::success([
                'quote_id'          => $quote->quote_id,
                'id'                => $quote->id,
                'hold_id'           => $quote->hold_id,
                'hold_reference'    => $quote->hold_reference,
                'customer_price'    => $quote->customer_price,
                'currency'          => $quote->currency,
                'selected_itinerary'=> $quote->selected_itinerary,
                'fare_details'      => $quote->fare_details,
                'pricing_breakdown' => $quote->pricing_breakdown,
                'status'            => $quote->status,
                'is_valid'          => $quote->isValid(),
                'is_expired'        => $quote->isExpired(),
                'expires_at'        => $quote->expires_at->toIso8601String(),
                'remaining_seconds' => $quote->remaining_seconds,
            ], 'Fare quote retrieved successfully.');
        } catch (Exception $e) {
            return ApiResponse::errors(null, 'Quote not found: ' . $e->getMessage(), 404);
        }
    }
}
