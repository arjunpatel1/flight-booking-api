<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Services\Catalog\FlightCatalogTenant;
use Modules\Flight\Models\FlightFareFamily;
use Modules\Support\ApiResponse;

class FlightFareFamilyController extends Controller
{
    /**
     * List fare families available for tenant (including global families).
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id
            ?? $request->attributes->get('tenant_id')
            ?? $request->attributes->get('tenant')?->id;

        return $this->fareFamiliesResponse($request, $tenantId ? (int) $tenantId : null);
    }

    /** List fare families for the authenticated admin tenant, including global families. */
    public function manageIndex(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id
            ?? $request->attributes->get('tenant_id')
            ?? $request->attributes->get('tenant')?->id;

        abort_unless($tenantId, 403, 'A tenant account is required to manage fare families.');

        return $this->fareFamiliesResponse($request, (int) $tenantId);
    }

    private function fareFamiliesResponse(Request $request, ?int $tenantId): JsonResponse
    {

        $query = FlightFareFamily::query()
            ->where(function ($q) use ($tenantId) {
                $q->whereNull('tenant_id');
                if ($tenantId) {
                    $q->orWhere('tenant_id', $tenantId);
                }
            });

        if ($cabin = $request->query('cabin_class')) {
            $query->where('cabin_class', strtoupper($cabin));
        }

        if ($request->boolean('active_only', true)) {
            $query->active();
        }

        if ($search = $request->query('search', $request->query('query'))) {
            $query->where(function ($q) use ($search) {
                $q->whereLike('code', "%{$search}%")
                    ->orWhereLike('name', "%{$search}%")
                    ->orWhereLike('description', "%{$search}%");
            });
        }

        $families = $query->orderBy('cabin_class')->orderBy('code')->get();

        return ApiResponse::success($families);
    }

    /**
     * Create a tenant-specific fare family.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id;

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('flight_fare_families', 'code')->where('tenant_id', $tenantId),
            ],
            'name' => ['required', 'string', 'max:100'],
            'cabin_class' => ['required', 'string', FlightCatalogTenant::activeCabinClass()],
            'description' => ['nullable', 'string'],
            'features' => ['nullable', 'array'],
            'baggage_allowance' => ['nullable', 'array'],
            'changeability' => ['nullable', 'array'],
            'refundability' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['code'] = strtoupper($validated['code']);
        $validated['cabin_class'] = strtoupper($validated['cabin_class']);
        $validated['tenant_id'] = $tenantId;

        $family = FlightFareFamily::create($validated);

        return ApiResponse::created($family, message: 'Fare family created successfully');
    }

    /**
     * Show a fare family.
     */
    public function show(int|string $identifier): JsonResponse
    {
        $family = is_numeric($identifier)
            ? FlightFareFamily::findOrFail($identifier)
            : FlightFareFamily::where('code', strtoupper($identifier))->firstOrFail();

        return ApiResponse::success($family);
    }

    /**
     * Update a fare family.
     */
    public function update(Request $request, int|string $identifier): JsonResponse
    {
        $family = is_numeric($identifier)
            ? FlightFareFamily::findOrFail($identifier)
            : FlightFareFamily::where('code', strtoupper($identifier))->firstOrFail();

        $tenantId = $request->user()?->tenant_id;

        $validated = $request->validate([
            'code' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('flight_fare_families', 'code')
                    ->where('tenant_id', $tenantId)
                    ->ignore($family->id),
            ],
            'name' => ['sometimes', 'string', 'max:100'],
            'cabin_class' => ['sometimes', 'string', FlightCatalogTenant::activeCabinClass()],
            'description' => ['nullable', 'string'],
            'features' => ['nullable', 'array'],
            'baggage_allowance' => ['nullable', 'array'],
            'changeability' => ['nullable', 'array'],
            'refundability' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (isset($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }
        if (isset($validated['cabin_class'])) {
            $validated['cabin_class'] = strtoupper($validated['cabin_class']);
        }

        $family->update($validated);

        return ApiResponse::success($family, message: 'Fare family updated successfully');
    }

    /**
     * Delete a fare family.
     */
    public function destroy(int|string $identifier): JsonResponse
    {
        $family = is_numeric($identifier)
            ? FlightFareFamily::findOrFail($identifier)
            : FlightFareFamily::where('code', strtoupper($identifier))->firstOrFail();

        $family->delete();

        return ApiResponse::success(null, message: 'Fare family deleted successfully');
    }
}
