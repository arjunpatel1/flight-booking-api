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
}
