<?php

namespace Modules\Flight\Services\Booking;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Flight\Enums\BookingStatus;
use Modules\Flight\Enums\PaymentStatus;
use Modules\Flight\Enums\TicketStatus;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightInventory;
use Modules\Flight\Models\FlightRoute;
use Modules\Flight\Services\Servicing\FlightServicingService;
use Modules\Flight\Services\Ticketing\FlightTicketingService;
use Modules\Saas\Models\Tenant;

/**
 * Hands out an airline PNR on our own (admin-managed) routes.
 *
 * The airline often confirms fewer seats than we sold, so the administrator
 * picks which paid bookings the PNR covers; everyone else still waiting on that
 * flight is "sold out": cancelled with a full refund and told so by email.
 * Bookings that never get a PNR within the tenant's time limit go the same way.
 */
class FlightPnrAllocationService
{
    public const DEFAULT_TIMEOUT_MINUTES = 30;

    public function __construct(
        protected FlightBookingService $bookingService,
        protected FlightTicketingService $ticketingService,
        protected FlightServicingService $servicingService,
    ) {}

    /** The flight's inventory rows (one per cabin) behind a dated route. */
    private function routeInventoryIds(FlightRoute $route): Collection
    {
        return FlightInventory::query()
            ->where('tenant_id', $route->tenant_id)
            ->where('flight_number', $route->flight_number)
            ->where('origin_airport_id', $route->origin_airport_id)
            ->where('destination_airport_id', $route->destination_airport_id)
            ->when($route->flight_time, fn (Builder $query) => $query->whereTime('departure_time', $route->flight_time))
            ->pluck('id');
    }

    /** The PNR most recently given to a booking on this flight that day, if any. */
    public function lastAllocatedPnr(FlightRoute $route): ?string
    {
        $departureDate = $route->flight_date?->toDateString();
        $inventoryIds = $departureDate ? $this->routeInventoryIds($route) : collect();
        if ($inventoryIds->isEmpty()) {
            return null;
        }

        return FlightBooking::query()
            ->where('tenant_id', $route->tenant_id)
            ->whereIn('flight_inventory_id', $inventoryIds)
            ->where('manual_pnr_required', true)
            ->whereNotNull('pnr')
            ->where('pnr', '!=', '')
            ->latest('updated_at')
            ->limit(50)
            ->get(['pnr', 'itinerary'])
            ->first(fn (FlightBooking $booking): bool => FlightBooking::departureDateOf((array) ($booking->itinerary ?? [])) === $departureDate)
            ?->pnr;
    }

    /** Paid bookings on this route's flight and date still waiting for a PNR, first paid first. */
    public function pendingBookingsForRoute(FlightRoute $route): Collection
    {
        $departureDate = $route->flight_date?->toDateString();
        if (! $departureDate) {
            return collect();
        }

        $inventoryIds = $this->routeInventoryIds($route);
        if ($inventoryIds->isEmpty()) {
            return collect();
        }

        return FlightBooking::query()
            ->with('passengers')
            ->where('tenant_id', $route->tenant_id)
            ->whereIn('flight_inventory_id', $inventoryIds)
            ->where('manual_pnr_required', true)
            ->where('payment_status', PaymentStatus::PAID)
            ->where('booking_status', BookingStatus::PNR_PENDING)
            ->orderBy('confirmed_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (FlightBooking $booking): bool => FlightBooking::departureDateOf((array) ($booking->itinerary ?? [])) === $departureDate)
            ->values();
    }

    /**
     * Give $pnr to the chosen bookings on this route and, when asked, sell out
     * everyone else still waiting on it.
     *
     * @param  string[]  $bookingReferences
     * @return array{assigned: string[], sold_out: string[], ticket_failed: string[]}
     */
    public function allocate(FlightRoute $route, string $pnr, array $bookingReferences, bool $soldOutRemaining): array
    {
        $pnr = strtoupper(trim($pnr));
        if ($pnr === '') {
            throw new DomainException('Enter the airline PNR first.');
        }

        $pending = $this->pendingBookingsForRoute($route);
        $chosen = array_values(array_unique(array_map('strval', $bookingReferences)));
        $unknown = array_diff($chosen, $pending->pluck('booking_reference')->all());
        if ($unknown !== []) {
            throw new DomainException('These bookings are not waiting for a PNR on this flight: ' . implode(', ', $unknown) . '.');
        }

        $result = ['assigned' => [], 'sold_out' => [], 'ticket_failed' => []];

        foreach ($pending as $booking) {
            if (in_array($booking->booking_reference, $chosen, true)) {
                $assigned = $this->bookingService->assignPnr($booking->booking_reference, $pnr);
                $result['assigned'][] = $assigned->booking_reference;
                if (! $this->issueTicket($assigned)) {
                    $result['ticket_failed'][] = $assigned->booking_reference;
                }
            } elseif ($soldOutRemaining) {
                $this->markSoldOut($booking, 'the flight was sold out before your seat could be confirmed.');
                $result['sold_out'][] = $booking->booking_reference;
            }
        }

        return $result;
    }

    /**
     * Issue the e-ticket after a PNR is assigned. A failure keeps the PNR (the
     * admin's work is not undone) and marks the ticket for a retry.
     */
    public function issueTicket(FlightBooking $booking): bool
    {
        try {
            $this->ticketingService->issueTicket($booking->fresh());

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Automatic ticket issuance failed after PNR assignment.', [
                'booking_reference' => $booking->booking_reference,
                'error' => $exception->getMessage(),
            ]);
            $booking->refresh()->update([
                'ticket_status' => TicketStatus::FAILED,
                'ticketing_error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /** Cancel a paid booking we could not seat: full refund + "sold out" email. */
    public function markSoldOut(FlightBooking $booking, string $why): array
    {
        $booking = $booking->fresh();
        if (! $booking->manual_pnr_required || $booking->booking_status !== BookingStatus::PNR_PENDING) {
            throw new DomainException('Only a paid booking still waiting for a PNR can be marked sold out.');
        }

        return $this->servicingService->cancelBooking(
            $booking,
            FlightBooking::SOLD_OUT_REASON_PREFIX . $why,
            'system:pnr-allocation',
            fullRefund: true,
        );
    }

    /** Minutes after payment a booking may wait for its PNR; 0 turns the auto-cancel off. */
    public static function timeoutMinutes(?Tenant $tenant): int
    {
        return max(0, (int) data_get($tenant?->settings, 'flight_booking.pnr_confirmation_timeout_minutes', self::DEFAULT_TIMEOUT_MINUTES));
    }

    /** Sell out paid bookings whose PNR did not arrive within their tenant's time limit. */
    public function expireUnconfirmed(?Carbon $now = null): int
    {
        $now ??= now();
        $expired = 0;
        $timeouts = [];

        FlightBooking::query()
            ->where('manual_pnr_required', true)
            ->where('payment_status', PaymentStatus::PAID)
            ->where('booking_status', BookingStatus::PNR_PENDING)
            ->whereNotNull('confirmed_at')
            ->orderBy('id')
            ->chunkById(100, function (Collection $bookings) use ($now, &$expired, &$timeouts): void {
                foreach ($bookings as $booking) {
                    $tenantId = (int) $booking->tenant_id;
                    $timeouts[$tenantId] ??= self::timeoutMinutes(Tenant::query()->withoutGlobalScopes()->find($tenantId));
                    $minutes = $timeouts[$tenantId];
                    if ($minutes === 0 || $booking->confirmed_at->copy()->addMinutes($minutes)->gt($now)) {
                        continue;
                    }

                    try {
                        $this->markSoldOut($booking, "the airline did not confirm your seat within {$minutes} minutes of payment.");
                        $expired++;
                    } catch (\Throwable $exception) {
                        // One bad booking must not stop the rest; it is retried next run.
                        Log::warning('Could not auto-cancel a booking without a PNR.', [
                            'booking_reference' => $booking->booking_reference,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        return $expired;
    }
}
