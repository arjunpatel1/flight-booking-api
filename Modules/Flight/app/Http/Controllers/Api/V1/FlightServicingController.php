<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Services\Servicing\FlightServicingService;
use Modules\Saas\Support\TenantContext;

class FlightServicingController extends Controller
{
    public function __construct(
        protected FlightServicingService $servicingService
    ) {}

    /**
     * Get servicing manifest (cancellation preview, reschedule eligibility, refund status).
     * GET /api/v1/flight/bookings/{identifier}/servicing
     */
    public function show(Request $request, string $identifier): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $booking = $this->resolveBooking($identifier, $tenantId);

        $summary = $this->servicingService->getServicingSummary($booking);

        return response()->json([
            'success' => true,
            'body'    => $summary,
        ]);
    }

    /**
     * Preview cancellation fee and refundable amount.
     * GET /api/v1/flight/bookings/{identifier}/cancellation-quote
     */
    public function quoteCancellation(Request $request, string $identifier): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $booking = $this->resolveBooking($identifier, $tenantId);

        try {
            $quote = $this->servicingService->quoteCancellation($booking);

            return response()->json([
                'success' => true,
                'body'    => $quote,
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Confirm and execute cancellation with automatic refund processing.
     * POST /api/v1/flight/bookings/{identifier}/cancel
     */
    public function cancel(Request $request, string $identifier): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $booking = $this->resolveBooking($identifier, $tenantId);

        $reason = $request->input('reason', 'Customer requested cancellation');
        $actor = $request->user()?->email ?? 'agent';

        try {
            $result = $this->servicingService->cancelBooking($booking, $reason, $actor);

            return response()->json([
                'success' => true,
                'message' => 'Flight booking cancelled and refund processed successfully.',
                'body'    => $result,
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Search available alternative flights for rescheduling.
     * GET /api/v1/flight/bookings/{identifier}/reschedule/search
     */
    public function searchReplacement(Request $request, string $identifier): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $booking = $this->resolveBooking($identifier, $tenantId);

        try {
            $results = $this->servicingService->searchReplacementFlights($booking, $request->all());

            return response()->json([
                'success' => true,
                'body'    => $results,
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Execute reschedule to a new replacement flight.
     * POST /api/v1/flight/bookings/{identifier}/reschedule
     */
    public function reschedule(Request $request, string $identifier): JsonResponse
    {
        $request->validate([
            'new_inventory_id' => 'required|integer',
            'new_fare_id'      => 'nullable|integer',
            'reason'           => 'nullable|string',
        ]);

        $tenantId = app(TenantContext::class)->id();
        $booking = $this->resolveBooking($identifier, $tenantId);

        $newInventoryId = (int) $request->input('new_inventory_id');
        $newFareId = $request->input('new_fare_id') ? (int) $request->input('new_fare_id') : null;
        $reason = $request->input('reason', 'Customer requested flight reschedule');
        $actor = $request->user()?->email ?? 'agent';

        try {
            $updated = $this->servicingService->rescheduleBooking(
                $booking,
                $newInventoryId,
                $newFareId,
                $reason,
                $actor
            );

            $summary = $this->servicingService->getServicingSummary($updated);

            return response()->json([
                'success' => true,
                'message' => 'Flight booking successfully rescheduled to new flight.',
                'body'    => $summary,
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    protected function resolveBooking(string $identifier, ?int $tenantId): FlightBooking
    {
        $query = FlightBooking::where('booking_reference', $identifier)
            ->orWhere('id', is_numeric($identifier) ? (int) $identifier : 0);

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->firstOrFail();
    }
}
