<?php

namespace Modules\Flight\Services\Ticketing;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Flight\Enums\BookingStatus;
use Modules\Flight\Enums\PaymentStatus;
use Modules\Flight\Enums\TicketStatus;
use Modules\Flight\Events\FlightTicketIssued;
use Modules\Flight\Exceptions\FlightTicketingException;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightTicket;
use Modules\Flight\Services\Supplier\Adapters\MockSupplierAdapter;
use Modules\Flight\Services\Supplier\Contracts\SupplierInterface;
use Modules\Flight\Services\Supplier\DTOs\SupplierBookRequest;
use Modules\Flight\Services\Supplier\DTOs\SupplierTicketRequest;
use Modules\Flight\Services\Supplier\SupplierManager;
use Throwable;

class FlightTicketingService
{
    public function __construct(
        protected FlightEticketPdfService $pdfService,
        protected ?SupplierManager $supplierManager = null
    ) {}

    /**
     * Alias for issueTicket
     */
    public function issueTickets(FlightBooking $booking, array $options = []): FlightBooking
    {
        return $this->issueTicket($booking, $options);
    }

    /**
     * Issue PNR, Supplier Booking and E-Tickets for a confirmed booking.
     * IDEMPOTENT: If already ticketed, returns existing tickets.
     */
    public function issueTicket(FlightBooking $booking, array $options = []): FlightBooking
    {
        return DB::transaction(function () use ($booking, $options) {
            $booking = FlightBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $booking->loadMissing(['passengers', 'ancillaries', 'flightInventory.airline']);

            // 1. Invariant: Unpaid or unconfirmed bookings CANNOT be ticketed
            $paymentStatus = is_object($booking->payment_status) ? $booking->payment_status->value : (string) $booking->payment_status;
            if (strtoupper($paymentStatus) !== 'PAID') {
                throw new DomainException("Cannot issue tickets for unpaid booking [{$booking->booking_reference}]. Payment is {$paymentStatus}.");
            }

            // Invariant: Verify payment in database (if payment records exist, ensure COMPLETED status)
            if ($booking->payments()->exists()) {
                $hasCompletedPayment = $booking->payments()
                    ->where('status', 'COMPLETED')
                    ->exists();

                if (! $hasCompletedPayment) {
                    throw new DomainException("Authoritative payment record for booking [{$booking->booking_reference}] is not completed in database.");
                }
            }

            $bookingStatus = is_object($booking->booking_status) ? $booking->booking_status->value : (string) $booking->booking_status;
            if (in_array(strtoupper($bookingStatus), ['CANCELLED', 'FAILED'], true)) {
                throw new DomainException("Cannot issue tickets for booking in [{$bookingStatus}] state.");
            }

            $supplierCode = strtoupper((string) ($booking->supplier_code ?? ''));
            $isAdminManagedRoute = $booking->flight_inventory_id !== null
                && in_array($supplierCode, ['INTERNAL', 'DIRECT'], true);
            if (($booking->manual_pnr_required || $isAdminManagedRoute) && empty($booking->pnr)) {
                throw new DomainException('This paid booking is waiting for an administrator to assign its airline PNR before ticketing.');
            }

            // 2. IDEMPOTENCY GUARD: Already ticketed? Return existing
            if ($booking->tickets()->exists() && ! empty($booking->pnr)) {
                return $booking->load('tickets');
            }

            // 3. Resolve Supplier Adapter
            $supplierCode = $booking->supplier_code ?: 'MOCK';
            $supplier = $this->resolveSupplier($supplierCode);

            // 4. Supplier Booking & PNR Creation (Idempotent: Skip if PNR already exists from prior attempt)
            $pnr = $booking->pnr;
            $supplierBookingRef = $booking->supplier_booking_reference;

            if (empty($pnr)) {
                try {
                    $paxData = [];
                    foreach ($booking->passengers as $pax) {
                        $paxData[] = [
                            'passenger_id'   => $pax->id,
                            'title'          => $pax->title,
                            'first_name'     => $pax->first_name,
                            'last_name'      => $pax->last_name,
                            'passenger_type' => $pax->passenger_type->value ?? 'ADULT',
                            'passport_no'    => $pax->passport_number,
                            'date_of_birth'  => $pax->date_of_birth?->toDateString(),
                        ];
                    }

                    $bookRequest = new SupplierBookRequest(
                        offerReference: $booking->quote_reference ?: $booking->booking_reference,
                        holdReference:  $booking->hold_reference,
                        passengers:     $paxData,
                        paymentDetails: [
                            'contact_name'   => $booking->contact_name,
                            'contact_email'  => $booking->contact_email,
                            'contact_phone'  => $booking->contact_phone,
                            'idempotency_key'=> $booking->booking_reference,
                        ]
                    );

                    $bookResponse = $supplier->book($bookRequest);
                    if (! $bookResponse->success || empty($bookResponse->supplierPnr)) {
                        throw new FlightTicketingException('Supplier failed to create PNR reservation.');
                    }

                    $pnr = strtoupper($bookResponse->supplierPnr);
                    $supplierBookingRef = $bookResponse->bookingReference;

                    // PNR Collision Guard: Guarantee uniqueness across bookings
                    $attempts = 0;
                    while (FlightBooking::where('pnr', $pnr)->where('id', '!=', $booking->id)->exists() && $attempts < 5) {
                        $pnr = substr($pnr, 0, 4) . strtoupper(Str::random(2));
                        $attempts++;
                    }

                    // Immediately persist PNR and supplier reference so subsequent failures do not re-book
                    $booking->update([
                        'pnr'                        => $pnr,
                        'supplier_pnr'               => $pnr,
                        'supplier_booking_reference' => $supplierBookingRef,
                    ]);
                } catch (Throwable $e) {
                    $booking->update([
                        'ticket_status'   => TicketStatus::FAILED,
                        'ticketing_error' => 'STAGE_SUPPLIER_BOOKING_FAILED: ' . $e->getMessage(),
                    ]);
                    throw new FlightTicketingException('Supplier booking failed: ' . $e->getMessage(), $supplierCode);
                }
            }

            // 5. Supplier Ticket Issuance (Resumes safely using existing PNR on retry)
            $ticketNumbers = [];
            try {
                $ticketRequest = new SupplierTicketRequest(
                    bookingReference: $supplierBookingRef ?: ('BK-' . $booking->booking_reference),
                    supplierPnr:      $pnr,
                );

                $ticketResponse = $supplier->ticket($ticketRequest);
                if (! $ticketResponse->success) {
                    throw new FlightTicketingException('Supplier ticketing execution failed.');
                }
                $ticketNumbers = $ticketResponse->ticketNumbers;
            } catch (Throwable $e) {
                $booking->update([
                    'pnr'                        => $pnr,
                    'supplier_pnr'               => $pnr,
                    'supplier_booking_reference' => $supplierBookingRef,
                    'ticket_status'              => TicketStatus::FAILED,
                    'ticketing_error'            => 'STAGE_SUPPLIER_TICKETING_FAILED: ' . $e->getMessage(),
                ]);
                event(new \Modules\Flight\Events\FlightTicketFailed($booking, $e->getMessage()));
                throw new FlightTicketingException('Supplier ticket issuance failed: ' . $e->getMessage(), $supplierCode, $pnr);
            }

            // 6. Passenger Ticket Association (Seats, Baggage, E-Tickets)
            $passengers = $booking->passengers;
            $paxCount = max(1, $passengers->count());
            $farePerPax = round((float) $booking->total_amount / $paxCount, 2);
            $airlineCode = $booking->flightInventory?->airline?->iata_code ?? 'AI';

            $createdTickets = [];
            $ticketIdx = 0;

            foreach ($passengers as $pax) {
                // Determine Seat from Ancillaries or Preference
                $seatAncillary = $booking->ancillaries
                    ->where('passenger_id', $pax->id)
                    ->where('category', 'SEAT')
                    ->first();
                $seatNumber = $seatAncillary?->seat_number ?? ($pax->seat_preference ?: 'Auto-Assigned');

                // Determine Baggage
                $baggageAncillary = $booking->ancillaries
                    ->where('passenger_id', $pax->id)
                    ->where('category', 'BAGGAGE')
                    ->first();
                $baggageAllowance = $baggageAncillary ? "{$baggageAncillary->name} ({$baggageAncillary->code})" : '15kg Check-in + 7kg Cabin';

                // Ticket Number assignment
                $tktNumber = $ticketNumbers[$ticketIdx] ?? ('098-' . mt_rand(1000000000, 9999999999));
                $ticketIdx++;

                $ticket = FlightTicket::create([
                    'tenant_id'                   => $booking->tenant_id,
                    'flight_booking_id'           => $booking->id,
                    'flight_booking_passenger_id' => $pax->id,
                    'ticket_number'               => $tktNumber,
                    'e_ticket_number'             => 'ETKT-' . strtoupper(Str::random(10)),
                    'pnr'                         => $pnr,
                    'supplier_code'               => $supplierCode,
                    'status'                      => TicketStatus::ISSUED,
                    'seat_number'                 => $seatNumber,
                    'baggage_allowance'           => $baggageAllowance,
                    'meal_preference'             => $pax->meal_preference ?: 'Standard',
                    'fare_amount'                 => $farePerPax,
                    'tax_amount'                  => 0.00,
                    'total_amount'                => $farePerPax,
                    'currency'                    => $booking->currency ?: 'INR',
                    'validating_carrier'          => $airlineCode,
                    'issued_at'                   => now(),
                ]);

                $createdTickets[] = $ticket;
            }

            // 7. Update Flight Booking State
            $booking->update([
                'pnr'                        => $pnr,
                'supplier_booking_reference' => $supplierBookingRef,
                'supplier_pnr'               => $pnr,
                'ticket_status'              => TicketStatus::ISSUED,
                'ticketed_at'                => now(),
                'ticketing_error'            => null,
                // A manually PNR-managed flight is booked and ticketed, but
                // the trip itself is not completed until it has been flown.
                'booking_status'             => $booking->manual_pnr_required
                    ? BookingStatus::BOOKED
                    : BookingStatus::COMPLETED,
            ]);

            // 8. Generate and store PDF E-Ticket
            try {
                $this->pdfService->generate($booking);
            } catch (Throwable $e) {
                // Non-fatal for ticket issuance
            }

            // 9. Dispatch Event
            event(new \Modules\Flight\Events\FlightPnrGenerated($booking, $pnr));
            event(new FlightTicketIssued($booking, $createdTickets));

            return $booking->refresh()->load('tickets.passenger');
        });
    }

    /**
     * Get comprehensive ticket & itinerary manifest for frontend consumption.
     */
    public function getTicketSummary(FlightBooking $booking): array
    {
        $booking->loadMissing(['passengers.ticket', 'tickets', 'ancillaries']);

        $ticketsData = [];
        foreach ($booking->passengers as $pax) {
            $tkt = $pax->ticket;
            $ticketsData[] = [
                'passenger_id'      => $pax->id,
                'passenger_name'    => $pax->full_name,
                'passenger_type'    => $pax->passenger_type->value ?? 'ADULT',
                'ticket_id'         => $tkt?->id,
                'ticket_number'     => $tkt?->ticket_number,
                'e_ticket_number'   => $tkt?->e_ticket_number,
                'status'            => $tkt?->status->value ?? 'PENDING',
                'status_label'      => $tkt?->status->label() ?? 'Pending',
                'seat_number'       => $tkt?->seat_number ?? ($pax->seat_preference ?: 'Unassigned'),
                'baggage_allowance' => $tkt?->baggage_allowance ?? 'Standard',
                'meal_preference'   => $tkt?->meal_preference ?? ($pax->meal_preference ?: 'Standard'),
                'fare_amount'       => $tkt?->fare_amount ?? 0.00,
                'currency'          => $tkt?->currency ?? $booking->currency,
            ];
        }

        return [
            'booking_reference'          => $booking->booking_reference,
            'pnr'                        => $booking->pnr,
            'ticket_status'              => is_object($booking->ticket_status) ? $booking->ticket_status->value : (string) $booking->ticket_status,
            'booking_status'             => is_object($booking->booking_status) ? $booking->booking_status->value : (string) $booking->booking_status,
            'payment_status'             => is_object($booking->payment_status) ? $booking->payment_status->value : (string) $booking->payment_status,
            'payment_reference'          => $booking->payment_reference,
            'supplier_booking_reference' => $booking->supplier_booking_reference,
            'supplier_pnr'               => $booking->supplier_pnr,
            'ticketed_at'                => $booking->ticketed_at?->toIso8601String(),
            'ticketing_error'            => $booking->ticketing_error,
            'itinerary'                  => $booking->itinerary,
            'pricing_breakdown'          => $booking->pricing_breakdown,
            'total_amount'               => (float) $booking->total_amount,
            'currency'                   => $booking->currency,
            'tickets'                    => $ticketsData,
            'ancillaries'                => $booking->ancillaries,
        ];
    }

    /**
     * Download or return raw binary PDF of the e-ticket.
     */
    public function generateEticketPdf(FlightBooking $booking): string
    {
        return $this->pdfService->generate($booking);
    }

    protected function resolveSupplier(string $supplierCode): SupplierInterface
    {
        if ($this->supplierManager) {
            try {
                return $this->supplierManager->driver($supplierCode);
            } catch (Throwable) {
                // fallback to mock
            }
        }

        return new MockSupplierAdapter();
    }
}
