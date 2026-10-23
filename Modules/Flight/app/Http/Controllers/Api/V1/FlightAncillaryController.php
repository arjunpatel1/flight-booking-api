<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Services\Ancillary\FlightAncillaryService;
use Modules\Saas\Models\Tenant;
use Modules\Support\ApiResponse;

class FlightAncillaryController extends Controller
{
    public function __construct(
        protected FlightAncillaryService $ancillaryService
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
     * Get visual seat map with real-time lock/availability states.
     * GET /api/v1/flight/inventories/{inventoryId}/seat-map
     */
    public function getSeatMap(Request $request, int $inventoryId): JsonResponse
    {
        try {
            $tenant = $this->resolveTenant($request);
            $cabinClass = $request->input('cabin_class', 'ECONOMY');
            $seatMap = $this->ancillaryService->getSeatMap($inventoryId, $tenant, $cabinClass);

            return ApiResponse::success($seatMap);
        } catch (DomainException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 404);
        } catch (\Throwable $e) {
            return ApiResponse::errors(null, $e->getMessage(), 500);
        }
    }

    /**
     * Lock a seat with distributed concurrency safety and TTL.
     * POST /api/v1/flight/inventories/{inventoryId}/seats/lock
     */
    public function lockSeat(Request $request, int $inventoryId): JsonResponse
    {
        $validated = $request->validate([
            'seat_number'  => 'required|string|max:10',
            'passenger_id' => 'nullable|integer',
            'booking_id'   => 'nullable|integer',
            'ttl_seconds'  => 'nullable|integer|min:60|max:1800',
        ]);

        try {
            $tenant = $this->resolveTenant($request);
            $ttl = (int) ($validated['ttl_seconds'] ?? 600);
            $lock = $this->ancillaryService->lockSeat(
                $inventoryId,
                $validated['seat_number'],
                $validated['passenger_id'] ?? null,
                $validated['booking_id'] ?? null,
                $ttl,
                $tenant
            );

            return ApiResponse::success([
                'id'                => $lock->id,
                'seat_number'       => $lock->seat_number,
                'lock_token'        => $lock->lock_token,
                'status'            => $lock->status->value,
                'status_label'      => $lock->status->label(),
                'expires_at'        => $lock->expires_at->toIso8601String(),
                'remaining_seconds' => $lock->remaining_seconds,
            ]);
        } catch (DomainException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return ApiResponse::errors(null, $e->getMessage(), 500);
        }
    }

    /**
     * Release a temporary seat lock.
     * POST /api/v1/flight/inventories/{inventoryId}/seats/release
     */
    public function releaseSeatLock(Request $request, int $inventoryId): JsonResponse
    {
        $validated = $request->validate([
            'lock_token' => 'required|string|max:64',
        ]);

        $tenant = $this->resolveTenant($request);
        $released = $this->ancillaryService->releaseSeatLock($validated['lock_token'], $tenant);

        return ApiResponse::success([
            'released' => $released,
            'message'  => $released ? 'Seat lock released successfully.' : 'Seat lock not found or already released.',
        ]);
    }

    /**
     * Retrieve available ancillaries catalog (Baggage, Meals, Lounge, Special Services).
     * GET /api/v1/flight/inventories/{inventoryId}/ancillaries
     */
    public function getAncillaries(Request $request, int $inventoryId): JsonResponse
    {
        try {
            $tenant = $this->resolveTenant($request);
            $ancillaries = $this->ancillaryService->getAvailableAncillaries($inventoryId, $tenant);

            return ApiResponse::success($ancillaries);
        } catch (DomainException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 404);
        } catch (\Throwable $e) {
            return ApiResponse::errors(null, $e->getMessage(), 500);
        }
    }

    /**
     * Add selected ancillary or seat to a booking passenger and update total price.
     * POST /api/v1/flight/bookings/{identifier}/ancillaries
     */
    public function addAncillaryToBooking(Request $request, string $identifier): JsonResponse
    {
        $validated = $request->validate([
            'passenger_id'  => 'required|integer',
            'category'      => 'required|string',
            'code'          => 'required|string|max:50',
            'name'          => 'nullable|string|max:100',
            'description'   => 'nullable|string',
            'price'         => 'nullable|numeric|min:0',
            'currency'      => 'nullable|string|max:3',
            'quantity'      => 'nullable|integer|min:1|max:10',
            'seat_number'   => 'nullable|string|max:10',
            'lock_token'    => 'nullable|string|max:64',
            'ancillary_id'  => 'nullable|integer',
            'meta'          => 'nullable|array',
        ]);

        try {
            $tenant = $this->resolveTenant($request);
            $booking = $this->ancillaryService->addAncillaryToBooking(
                $identifier,
                $validated['passenger_id'],
                $validated,
                $tenant
            );

            return ApiResponse::success([
                'booking_reference' => $booking->booking_reference,
                'total_amount'      => (float) $booking->total_amount,
                'currency'          => $booking->currency,
                'ancillaries'       => $booking->ancillaries->map(fn ($a) => [
                    'id'           => $a->id,
                    'passenger_id' => $a->passenger_id,
                    'category'     => $a->category->value,
                    'code'         => $a->code,
                    'name'         => $a->name,
                    'price'        => (float) $a->price,
                    'quantity'     => $a->quantity,
                    'seat_number'  => $a->seat_number,
                    'status'       => $a->status,
                ]),
                'message'           => 'Ancillary added successfully and booking total updated.',
            ]);
        } catch (DomainException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return ApiResponse::errors(null, $e->getMessage(), 500);
        }
    }

    /**
     * Remove an ancillary from a booking and deduct from total.
     * DELETE /api/v1/flight/bookings/{identifier}/ancillaries/{ancillaryId}
     */
    public function removeAncillaryFromBooking(Request $request, string $identifier, int $ancillaryId): JsonResponse
    {
        try {
            $tenant = $this->resolveTenant($request);
            $booking = $this->ancillaryService->removeAncillaryFromBooking($identifier, $ancillaryId, $tenant);

            return ApiResponse::success([
                'booking_reference' => $booking->booking_reference,
                'total_amount'      => (float) $booking->total_amount,
                'currency'          => $booking->currency,
                'message'           => 'Ancillary removed and booking total recalculated.',
            ]);
        } catch (DomainException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return ApiResponse::errors(null, $e->getMessage(), 500);
        }
    }
}
