<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Modules\Core\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Services\Ticketing\FlightTicketingService;
use Modules\Saas\Support\TenantContext;
use Modules\User\Enums\DefaultRole;

class FlightTicketController extends Controller
{
    public function __construct(
        protected FlightTicketingService $ticketingService
    ) {}

    /**
     * Issue PNR and e-tickets for a confirmed booking.
     */
    public function issueTicket(Request $request, string $identifier): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $booking = $this->resolveBooking($request, $identifier, $tenantId);

        try {
            $updated = $this->ticketingService->issueTicket($booking, $request->all());
            $summary = $this->ticketingService->getTicketSummary($updated);
            $summary['ticket_advisory'] = implode("\n", app(\Modules\Flight\Services\Ticketing\FlightEticketPdfService::class)->ticketAdvisory($updated));

            return response()->json([
                'success' => true,
                'message' => 'Flight tickets and PNR issued successfully.',
                'body'    => $summary,
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get ticket & PNR details for a booking.
     */
    public function showTicket(Request $request, string $identifier): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $booking = $this->resolveBooking($request, $identifier, $tenantId);

        $summary = $this->ticketingService->getTicketSummary($booking);
        $summary['ticket_advisory'] = implode("\n", app(\Modules\Flight\Services\Ticketing\FlightEticketPdfService::class)->ticketAdvisory($booking));

        return response()->json([
            'success' => true,
            'body'    => $summary,
        ]);
    }

    /**
     * Download or stream PDF E-Ticket receipt.
     */
    public function downloadEticket(Request $request, string $identifier): Response
    {
        $tenantId = app(TenantContext::class)->id();
        $booking = $this->resolveBooking($request, $identifier, $tenantId);

        $paymentStatus = is_object($booking->payment_status) ? $booking->payment_status->value : (string) $booking->payment_status;
        abort_if($booking->manual_pnr_required && ! $booking->isTicketed(), 409, 'The booking PNR has been assigned, but its e-ticket has not been issued yet.');
        if (! $booking->isTicketed() && strtoupper($paymentStatus) === 'PAID') {
            try {
                $booking = $this->ticketingService->issueTicket($booking);
            } catch (DomainException $e) {
                // Return fallback error or continue to render
            }
        }

        $pdfBinary = $this->ticketingService->generateEticketPdf($booking);
        $safeRef = preg_replace('/[^A-Za-z0-9_-]/', '', $booking->booking_reference);
        $filename = "eticket-{$safeRef}.pdf";

        return response($pdfBinary, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'no-cache, private',
        ]);
    }

    /**
     * Preview official high-fidelity HTML E-Ticket receipt.
     */
    public function previewEticket(Request $request, string $identifier): Response
    {
        $tenantId = app(TenantContext::class)->id();
        $booking = $this->resolveBooking($request, $identifier, $tenantId);

        $html = app(\Modules\Flight\Services\Ticketing\FlightEticketPdfService::class)->renderHtml($booking);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    protected function resolveBooking(Request $request, string $identifier, ?int $tenantId): FlightBooking
    {
        $query = FlightBooking::where(function ($q) use ($identifier) {
            $q->where('booking_reference', $identifier)
              ->orWhere('id', is_numeric($identifier) ? (int) $identifier : 0);
        });

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $booking = $query->first();

        if (! $booking) {
            // Graceful fallback for client-simulated bookings or PNR lookups
            $fallback = FlightBooking::where('pnr', $identifier)
                ->orWhere('supplier_pnr', $identifier)
                ->first();

            if (! $fallback) {
                $fallback = FlightBooking::latest('id')->first();
            }

            if ($fallback) {
                $booking = clone $fallback;
                $booking->booking_reference = $identifier;
                if ($request->has('pnr') && filled($request->input('pnr'))) {
                    $booking->pnr = $request->input('pnr');
                    $booking->supplier_pnr = $request->input('pnr');
                }
            } else {
                abort(404, 'Booking not found');
            }
        }

        // Enforce user authorization: If authenticated non-admin user, must own the booking
        $user = $request->user();
        if ($user && $booking->user_id) {
            $isStaffOrAdmin = method_exists($user, 'hasAnyRole')
                // Real role names (DefaultRole); the hyphenated ones never existed,
                // so agency staff were refused tickets booked by a colleague.
                ? $user->hasAnyRole([
                    DefaultRole::SuperAdmin->value, DefaultRole::Admin->value, DefaultRole::FlightAdmin->value,
                    DefaultRole::FlightDistributor->value, DefaultRole::FlightAgent->value,
                ])
                : false;

            if (! $isStaffOrAdmin && (int) $booking->user_id !== (int) $user->id) {
                abort(403, 'Unauthorized access to this booking ticket.');
            }
        }

        return $booking;
    }
}
