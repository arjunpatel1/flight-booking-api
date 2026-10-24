<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightNotificationDelivery;
use Modules\Flight\Services\Notification\FlightNotificationService;

class FlightNotificationController extends Controller
{
    public function __construct(
        protected FlightNotificationService $notificationService
    ) {}

    /**
     * Get notifications for a specific booking.
     * GET /api/v1/flight/bookings/{identifier}/notifications
     */
    public function bookingNotifications(Request $request, string $identifier): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id;
        $booking = FlightBooking::where('booking_reference', $identifier)
            ->orWhere('id', is_numeric($identifier) ? (int) $identifier : 0)
            ->first();

        if (! $booking) {
            return response()->json(['error' => 'Booking not found.'], 404);
        }

        // Tenant Isolation Check
        if ($tenantId && $booking->tenant_id && $booking->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Unauthorized access to booking notifications.'], 403);
        }

        $deliveries = $this->notificationService->getDeliveriesForBooking($booking->id, $tenantId);

        return response()->json([
            'success' => true,
            'booking' => [
                'id'                => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'pnr'               => $booking->pnr,
                'status'            => $booking->booking_status,
            ],
            'data'    => $deliveries,
        ]);
    }

    /**
     * Admin/Tenant paginated list of notification deliveries.
     * GET /api/v1/flight/notifications
     */
    public function indexNotifications(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id;
        $filters = $request->only(['event', 'channel', 'status', 'recipient', 'booking_reference']);

        $deliveries = $this->notificationService->listDeliveries(
            filters:  $filters,
            tenantId: $tenantId,
            perPage:  (int) $request->get('per_page', 20)
        );

        return response()->json([
            'success' => true,
            'data'    => $deliveries->items(),
            'meta'    => [
                'current_page' => $deliveries->currentPage(),
                'last_page'    => $deliveries->lastPage(),
                'total'        => $deliveries->total(),
                'per_page'     => $deliveries->perPage(),
            ],
        ]);
    }

    /**
     * Show single notification delivery record.
     * GET /api/v1/flight/notifications/{identifier}
     */
    public function showNotification(Request $request, string $identifier): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id;

        $delivery = FlightNotificationDelivery::with('booking')
            ->where('id', is_numeric($identifier) ? (int) $identifier : 0)
            ->first();

        if (! $delivery) {
            return response()->json(['error' => 'Notification record not found.'], 404);
        }

        // Tenant Isolation Check
        if ($tenantId && $delivery->tenant_id && $delivery->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Unauthorized access to notification record.'], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => $delivery,
        ]);
    }

    /**
     * Retry a notification delivery attempt.
     * POST /api/v1/flight/notifications/{identifier}/retry
     */
    public function retryNotification(Request $request, string $identifier): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id;

        $delivery = FlightNotificationDelivery::where('id', is_numeric($identifier) ? (int) $identifier : 0)->first();
        if (! $delivery) {
            return response()->json(['error' => 'Notification record not found.'], 404);
        }

        // Tenant Isolation Check
        if ($tenantId && $delivery->tenant_id && $delivery->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Unauthorized to retry notification.'], 403);
        }

        $retried = $this->notificationService->retry($delivery);

        return response()->json([
            'success' => true,
            'message' => 'Notification delivery retried.',
            'data'    => $retried,
        ]);
    }
}
